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

Успешная сборка создаёт исполняемый файл и рядом `my-application.manifest.json` с target, проверенными PHP version и полным списком загруженных extensions, отдельно с обязательными extensions из manifest, версиями backend, SHA-256 lockfile (если используется Composer), SHA-256 `micro.sfx` и SHA-256 файла. Перед сборкой CLI запускает временный probe на основе указанного `micro.sfx`, сравнивает точную версию PHP и наличие всех заявленных расширений. Дочерние процессы имеют таймаут 300 секунд, который записывается в metadata.

## Ограничения прототипа

- Нужен готовый `micro.sfx`; CLI проверяет его состав, но пока не собирает runtime.
- Из каталога исключаются `.git`, IDE-настройки, `node_modules`, `.env*`, `auth.json` и `composer-auth.json`. Остальные файлы проекта, включая тестовые, попадают в PHAR.
- При наличии `vendor/` упаковщик включает его как есть после проверки метаданных установленного графа. Устанавливайте только production зависимости по lockfile.
- Вывод представляет SHA-256, но пока не подписан; SBOM, attestations и воспроизводимые независимые сборки ещё не реализованы.
- Нет активации, выдачи или проверки лицензий. Не добавляйте секреты, сертификаты или закрытые ключи в проект.
- PHP-исходники остаются извлекаемыми из PHAR. Компоновка с micro SAPI не является шифрованием или защитой от анализа.
