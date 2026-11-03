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
- Ed25519 подпись и проверка целостности реализованы; provenance attestations, независимая проверка SPDX и воспроизводимые сборки ещё не реализованы.
- Нет активации, выдачи или проверки лицензий. Не добавляйте секреты, сертификаты или закрытые ключи в проект.
- PHP-исходники остаются извлекаемыми из PHAR. Компоновка с micro SAPI не является шифрованием или защитой от анализа.
