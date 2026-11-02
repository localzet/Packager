# Contributing

Use a focused branch and Conventional Commit messages. Keep changes small enough to review and include a regression check for behavior changes.

Before opening a pull request, run:

```sh
composer validate --strict
composer check
```

For Packager changes, test both rejection paths and a real standalone runtime where the change touches manifest validation or binary construction. Do not add actual project secrets, keys, certificates, private binaries or customer data to fixtures.

The project is an early prototype. Keep documentation explicit about tested operating systems, architectures, PHP versions and static-php-cli versions. Do not claim that PHAR or micro SAPI prevents source extraction.

Все изменения должны сопровождаться проверкой затронутого поведения. Для сборки используйте изолированное окружение; не добавляйте секреты и реальные бинарники в тестовые данные. В документации указывайте только фактически проверенные платформы и возможности.
