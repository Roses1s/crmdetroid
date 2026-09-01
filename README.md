# CRM «Детроид»

Канбан-CRM для лидов и направлений. Данные в **MySQL**, API — `site/api.php`, фронт — `site/index.html`.

## Запуск на SpaceWeb

См. `site/README.md`. Коротко: создать MySQL 8, прописать пароль в `config.php`, залить содержимое `site/` в `public_html` (**не** перезаписывая живой `config.php`). Включите SSL и открывайте сайт по **https://**.

Первый вход: `admin@detroid.local` / `admin123` — сразу смените пароль.

## Локально

Нужны PHP 8.1+ с `pdo_mysql` и MySQL. В `site/config.php` укажите доступ к базе, затем:

```
npm start
```

это поднимает `php -S 0.0.0.0:8080 -t site`.

## Тесты

```
npm test
```

Проверяют экранирование, запрет имени «Система», magic-bytes загрузок, синтаксис PHP и что в репозитории нет старого `crm.html`.
