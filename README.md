# CRM «Детроид» — MySQL API

Данные хранятся в **MySQL** (не в JSON). Фронт: `index.html`. API: `api.php?action=…`.

## SpaceWeb: база + заливка

1. Панель [cp.sweb.ru](https://cp.sweb.ru/main/) → **Базы данных** → **Создать**.
   - тип **MySQL 8** (не PostgreSQL).
   - запомните **имя БД**, **логин**, **пароль**.
2. Скачайте архив `crm-spaceweb.zip`, откройте `config.php` **на компьютере** и подставьте:

```php
define('CRM_DB_HOST', '127.0.0.1');  // MySQL 8 на SpaceWeb, не localhost
define('CRM_DB_PORT', '3308');
define('CRM_DB_NAME', 'uXXXX_crm');   // имя из панели
define('CRM_DB_USER', 'uXXXX_crm');   // логин из панели
define('CRM_DB_PASS', 'ваш_пароль');
```

3. Залейте архив в `public_html` (файловый менеджер → распаковать **«Сюда»**).
   Таблицы `crm_users`, `crm_leads`, `crm_comments`… создадутся **сами** при первом входе.
4. Сайт открывается по **http://**. SSL на хостинге пока не нужен; когда появится сертификат, можно включить его в панели SpaceWeb.
5. Первый вход: `admin@detroid.local` / `admin123`. CRM сразу попросит задать свой пароль (не короче 8 символов).

MySQL в панели создавать **нужно**. Импортировать `schema.sql` вручную не обязательно.

Обновление уже работающего сайта: залейте `api.php`, `db.php`, `index.html`, `ui.html`, `app.css`, `noscript.css`, `js/`, `.htaccess` (и при необходимости `uploads/.htaccess`). **Не перезаписывайте** живой `config.php` — там пароль MySQL.

## Локально

PHP 8.1+ с `pdo_mysql` и MySQL. Пропишите доступ в `config.php`, затем из корня репозитория:

```
npm start
```

поднимется `php -S 0.0.0.0:8080 -t site`. Node-API больше нет.

## Таблицы

| таблица | что внутри |
|---|---|
| `crm_users` | сотрудники, bcrypt-пароли, роль |
| `crm_stages` | колонки канбана **своего** аккаунта |
| `crm_leads` | карточки **своего** аккаунта |
| `crm_comments` | лог |
| `crm_attachments` | пути к файлам в `uploads/` |
