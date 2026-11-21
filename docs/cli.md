# CLI прототип

Текущая версия принимает JSON manifest, проверяет пути и параметры, упаковывает файлы проекта в PHAR и вызывает закреплённый static-php-cli `micro:combine`. Поддерживается только `linux-x86_64`.

## Подготовка проекта

Установите production-зависимости проекта до упаковки:

```sh
composer install --no-dev --no-scripts --no-plugins --prefer-dist --no-interaction
```

Команда должна выполняться в изолированной build-среде с доступом к проверенному lockfile. Packager не запускает Composer и не исполняет код проекта.

Соберите `micro.sfx` с нужными расширениями с помощью закреплённой версии static-php-cli. Укажите путь к CLI, micro runtime и ожидаемую версию backend в manifest.

## Manifest

```json
{
  "schema_version": 1,
  "project": {
    "root": "../my-application",
    "entrypoint": "server.php"
  },
  "target": "linux-x86_64",
  "php_version": "8.4.27",
  "extensions": ["phar", "pcntl", "posix", "sockets", "openssl", "ctype"],
  "toolchain": {
    "spc": "/opt/static-php-cli/bin/spc",
    "micro": "/opt/static-php-cli/buildroot/bin/micro.sfx",
    "version": "2.8.6"
  },
  "output": "../dist/my-application"
}
```

Пути `project.root`, `toolchain.spc`, `toolchain.micro` и `output` вычисляются относительно каталога manifest. Entry point обязан находиться внутри каталога проекта. Если в проекте есть `composer.json`, требуются корректные `composer.lock`, `vendor/autoload.php` и `vendor/composer/installed.json`. Состав, версии и доступные source/dist references production-пакетов в `vendor/` сверяются с `composer.lock`; dev-зависимости и расхождения останавливают сборку. SHA-256 lockfile записывается в metadata. Результат нельзя размещать внутри исходного дерева. Существующие artifact и manifest не перезаписываются.

### Лицензированная сборка

Добавьте в manifest объект `license`, если бинарник должен требовать онлайн-активацию. Product/release ID принимают буквы, цифры, точку, `_` и `-`; `product_version` — версия приложения. `api_url` задаёт HTTPS origin без пути. `trusted_keys` связывает `kid` из License API с открытым Ed25519 ключом в стандартном base64:

```jsonc
"license": {
  "product_id": "my-application",
  "release_id": "7.1.0",
  "product_version": "7.1.0",
  "api_url": "https://license-api.example.com",
  "trusted_keys": {
    "KID_FROM_SIGNER_PUBLIC_KEY_COMMAND": "BASE64_RAW_ED25519_PUBLIC_KEY"
  }
}
```

Значения в `trusted_keys` — placeholders. Получите их из команды `signer:public-key` в License API, проверьте источник по доверенному каналу и подставьте в manifest. `kid` — SHA-256 открытого ключа в unpadded base64url; поле `public_key` команды — стандартный base64. Никогда не включайте в manifest license key, access token, HMAC secret или закрытый ключ подписи.

Для лицензированной сборки target runtime должен включать `curl`, `pcntl`, `posix` и `sodium`, а `proc_open` должен быть доступен. Composer lock должен содержать Localzet Server 7 или новее. License API находится в приватном GitHub-репозитории; клиент проверен на локальном API, но production endpoint, HTTPS ingress и AWS KMS key ещё не настроены. Не выпускайте клиентские лицензии до проверки развернутого staging-сервиса.

Сначала активируйте бинарник. Ключ подаётся через stdin, чтобы он не оказался в аргументах процесса или shell history:

```bash
read -r -s -p "License key: " LICENSE_KEY
printf '\n'
printf '%s\n' "$LICENSE_KEY" | ./dist/my-application --license-activate
unset LICENSE_KEY
```

Активация создаёт случайный ID установки и сохраняет access token с подписанной lease в `$XDG_STATE_HOME/localzet-license/<product_id>` либо `$HOME/.local/state/localzet-license/<product_id>`. Переопределите базовый каталог переменной `LOCALZET_LICENSE_DIR`, если служебный пользователь использует отдельный state directory. Каталог получает режим `0700`, файлы — `0600`; путь должен оставаться постоянным между обновлениями бинарника.

Запускайте бинарник от того же системного пользователя, который владеет каталогом лицензии. Если master запускается от root и Server затем сбрасывает привилегии через `Server::$user`, заранее создайте каталог лицензии для worker-пользователя и активируйте бинарник от этого пользователя. Не активируйте его от root для worker-а с другим UID.

Для такого режима задайте постоянный базовый каталог и активируйте продукт от пользователя worker-а:

```bash
sudo install -d -o appuser -g appgroup -m 0700 /var/lib/my-application
read -r -s -p "License key: " LICENSE_KEY
printf '\n'
printf '%s\n' "$LICENSE_KEY" | sudo -u appuser env LOCALZET_LICENSE_DIR=/var/lib/my-application ./dist/my-application --license-activate
unset LICENSE_KEY
```

Укажите тот же `LOCALZET_LICENSE_DIR` в окружении master-процесса. Первый запуск без активации не создаёт каталог состояния; это позволяет активировать лицензию от правильного пользователя после неудачной проверки запуска.

Lease ограничивает срок офлайн-работы семью днями. Клиент запускает renewal в отдельном процессе по подписанному сроку проверки, не блокируя event loop; worker проверяет отказ API и срок lease, затем запрашивает штатное завершение Server. Если лицензия истекла или новый release/product version требует перепривязки, бинарник синхронно запросит renewal до запуска приложения. Для ручной попытки после восстановления сети используйте `./dist/my-application --license-renew`; `./dist/my-application --license-release` отзывает активацию через API. При истёкшем API/лицензии запуск блокируется. Часы на целевом сервере должны быть синхронизированы.

Изменение release ID или product version требует online renewal перед запуском новой сборки. Старый lease подписан для прежней версии; каталог состояния остаётся общим по product ID, поэтому установка и слот активации не теряются при обновлении.

## Проверка и сборка

```sh
composer install --no-interaction
php bin/localzet-packager validate packager.json
php -d phar.readonly=0 bin/localzet-packager build packager.json
```

Успешная сборка создаёт исполняемый файл, `my-application.manifest.json` и `my-application.spdx.json`. Metadata содержит target, проверенные PHP version и загруженные extensions, обязательные extensions из manifest, версии backend и SHA-256 lockfile, `micro.sfx`, бинарника и SBOM. SPDX 2.3 SBOM описывает приложение и production-пакеты из `composer.lock`, добавляет Composer PURL и известные связи зависимостей. Неподтверждённые сведения о лицензиях обозначаются `NOASSERTION`; это инвентаризация по lockfile, а не независимый анализ исходников. Перед сборкой CLI запускает временный probe на основе указанного `micro.sfx`, сравнивает точную версию PHP и наличие всех заявленных расширений. Дочерние процессы имеют таймаут 300 секунд, который записывается в metadata.

## Подпись и проверка артефакта

После сборки создайте отдельную пару Ed25519 ключей. Команда откажется перезаписывать существующие файлы; закрытый ключ получает права `0600`:

```sh
php bin/localzet-packager keygen /secure/release.key /secure/release.pub
```

Закрытый ключ храните вне исходного проекта и build worker. Подпишите бинарник вместе с соседними `.manifest.json` и `.spdx.json`:

```sh
php bin/localzet-packager sign ../dist/my-application /secure/release.key
```

Это создаёт `my-application.sig.json` с Ed25519 подписью, key ID и хешами всех трёх файлов. На целевой стороне проверяйте комплект по публичному ключу, полученному из доверенного канала:

```sh
php bin/localzet-packager verify ../dist/my-application /secure/trusted-release.pub
```

Публичный ключ внутри комплекта не передаётся и не считается доверенным автоматически. Подпись подтверждает целостность комплекта и владение соответствующим закрытым ключом; сама по себе она не подтверждает безопасность исходников, полномочия владельца ключа или происхождение сборочной среды. При компрометации ключа нужна его ротация через доверенный канал. Подпись не шифрует PHP-код.

## Ограничения прототипа

- Нужен готовый `micro.sfx`; CLI проверяет его состав, но пока не собирает runtime.
- Из каталога исключаются `.git`, IDE-настройки, `node_modules`, `.env*`, `auth.json` и `composer-auth.json`. Остальные файлы проекта, включая тестовые, попадают в PHAR.
- При наличии `vendor/` упаковщик включает его как есть после проверки метаданных установленного графа. Устанавливайте только production зависимости по lockfile.
- Ed25519 подпись и проверка целостности реализованы; SBOM проверяется по официальной JSON Schema в тестах. Семантическая проверка SPDX, provenance attestations и воспроизводимые сборки ещё не реализованы.
- Production API и KMS ещё не настроены; выдача клиентских лицензий по реальному сервису пока не проверена. Не добавляйте секреты, сертификаты или закрытые ключи в проект.
- PHP-исходники остаются извлекаемыми из PHAR. Компоновка с micro SAPI не является шифрованием или защитой от анализа.
