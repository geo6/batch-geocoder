# Batch Geocoder

## Install

The "Batch Geocoder" application requires [PostgreSQL 10.0+](https://www.postgresql.org/download/) !

To install the "Batch Geocoder" application :

    composer create-project geo6/batch-geocoder

The install process will create a new PostgreSQL user (`geocode`) and a new PostgreSQL database (`geocode`). You will be asked to set the PostgreSQL `geocode` user password !

## Configuration

The configuration files have to be in `config/application` directory. You can use `php`, `ini`, `xml`, `json`, or `yaml` file according to [`zend-config`](https://docs.zendframework.com/zend-config/reader/).

I use `yaml` files, but you can do exactly the same with the other formats depending on you preferences.  
If you want to use YAML, do not forget to install [YAML PECL extension](http://php.net/manual/en/book.yaml.php).

### Database

    postgresql:
        host: localhost
        port: 5432
        dbname: geocode
        user: geocode
        password: <YOURPASSWORD>

### Providers

The application is developed so everyone can use the Geocoder PHP providers (and client) he needs.

You can configure the application by adding a configuration file with `providers` parameter in `config/application` directory.  
Here is the one I use for Belgium :

    <?php

    use Geocoder\Provider;
    use Http\Adapter\Guzzle6\Client;

    $client = new Client();

    return [
        'providers' => [
            new Provider\Geo6\Geo6($client, '<MYCONSUMERID>', '<MYSECRETKEY>'),
            new Provider\UrbIS\UrbIS($client),
            new Provider\Geopunt\Geopunt($client),
            new Provider\SPW\SPW($client),
            new Provider\bpost\bpost($client),
        ]
    ];

You will have to install those providers, of course.  
For instance, to install `UrbIS` provider, just run :

    composer require geo6/geocoder-php-urbis-provider

If you need more information, have a look a [Geocoder PHP documentation](https://github.com/geocoder-php/Geocoder#geocoder) !

#### Dedicated providers

If you use validation process (for Belgian addresses), the application will try to guess in which Belgian region your address is (Brussels `bru`, Flander `vla`, or Wallonia `wal`).  
Some providers are regional providers (see each provider's documentation) ; you can define for each provider if it should be use for all regions or just specific regions.

Here is the same providers configuration but we define which provider to use for all of Belgium or just specific regions :

    <?php

    use Geocoder\Provider;
    use Http\Adapter\Guzzle6\Client;

    $client = new Client();

    return [
        'providers'  => [
            new Provider\Geo6\Geo6($client, '<MYCONSUMERID>', '<MYSECRETKEY>'),
            [new Provider\UrbIS\UrbIS($client), ['bru']],
            [new Provider\Geopunt\Geopunt($client), ['vla', 'bru']],
            [new Provider\SPW\SPW($client), ['wal']],
            new Provider\bpost\bpost($client),
        ]
    ];

Explanations:

- *GEO-6* provider will be used for all the addresses ;
- *UrbIS* provider will be only used for addresses in Brussels ;
- *Geopunt* provider will be only used for addresses in Brussels or Flanders (Vlaanderen) ;
- *SPW* provider will be only used for addresses in Wallonia ;
- *bpost* provider will be used for all the addresses ;

### Additional parameters

| Parameter name  | Type      | Description                                                |
|-----------------|-----------|------------------------------------------------------------|
| title           | *string*  | Title of the application (default: `batch-geocoder`)       |
| archives        | *boolean* | Enable archive mode (default: `false`) (see here under)    |
| limit           | *integer* | Maximum number of records allowed                          |
| validation      | *boolean* | Disable validation step (default: `true`) (see here under) |

All those parameters are optional.  
By default, archive mode is disabled and there is no limit of maximum number of records allowed.

#### Archive mode

If you enable archive mode, you can add `?archives` to display a listbox of existing tables : <http://localhost:8080/app/batch-geocoder/?archives>

#### Validation

This application is developed for **Belgian addresses**, it will work with any set of addresses depending on the providers you use
but the validation process (right after the upload process) will check if the postal code and locality is valid for each address
(see `scripts/create-validation-bpost.sql`).

If you want to disable this validation process, just set the `validation` parameter to `false`.
