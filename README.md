This plugin let Psalm better understand MySQL `SELECT`'s, executed via PDO.

Some assumptions
====

Plugin is developed initially for internal purpose, so there is some limitation. It assumes that PDO is configured as:
- `PDO::ATTR_ERRMODE` is set to `PDO::ERRMODE_EXCEPTION`, so query can't return `false`
- `PDO::ATTR_EMULATE_PREPARES` is set to `true`

It can be configured later, also pull requests are always welcome :) 


Usage example
=====

Database:
```sql
CREATE TABLE `users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255),
    PRIMARY KEY (`id`)
);
```

Code:
```php
<?php

/** @return array{id: string, username: string, email: ?string} */
function fetchUser(PDO $pdo): array {
    return $pdo->query("SELECT * FROM users WHERE id=1")->fetch(PDO::FETCH_ASSOC);
} 
```

Configuration
=====

You should generate database description, otherwise plugin will not register.

Use script `vendor/bin/dump-databases` to generate it:

```shell
vendor/bin/dump-databases mysql:host=localhost user mysecretpassword -- dbname > databases.xml
```

It executes following query:
```sql
SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = :schema
ORDER BY TABLE_NAME
```

Then add plugin configuration in `psalm.xml` as follows:

```xml
<psalm
        maxStringLength="1000" 
        xmlns:xi="http://www.w3.org/2001/XInclude">
<!--
    maxStringLength is important for analyzing long queries
    and XInclude is useful for including database declaration
    from other file
 -->
    <plugins>
        <pluginClass class="M03r\PsalmPDOMySQL\Plugin">
            <xi:include href="databases.xml" />
            <!-- if you use PDO-like wrapper/interface -->
            <PDOClass>App\Bundle\PDOInterface</PDOClass>
        </pluginClass>
        <!-- other plugins -->
    </plugins>
</psalm>
```

Please do not forget update `databases.xml` when modifying database schema.
