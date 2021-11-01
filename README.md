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
     - plugin.php
     - drivers/
         - sybase.inc.php
```

Example of index.php:
```php
function adminer_object() {
    include_once "./plugins/drivers/sybase.inc.php";

    // required to run any plugin
    include_once "./plugins/plugin.php";
    
    return new AdminerPlugin($plugins);
}
// include original Adminer or Adminer Editor
include "./adminer.php";