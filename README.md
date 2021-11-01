# adminer-sybase-driver
SYBASE driver

# Install
[Detailed Information](https://www.adminer.org/en/plugins/)

Download sybase.inc.php file to plugins/drivers folder in your server.

Example folder construction:
```
adminer-folder/
 - adminer.php
 - index.php
 - plugins/
     - drivers/
         - sybase.inc.php
```

Example of index.php:
```php
include_once "./plugins/drivers/sybase.inc.php";
include "./adminer.php";