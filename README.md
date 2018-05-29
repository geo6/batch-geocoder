# Batch Geocoder

[![Latest Stable Version](https://poser.pugx.org/geo6/batch-geocoder/v/stable)](https://packagist.org/packages/geo6/batch-geocoder)
[![Total Downloads](https://poser.pugx.org/geo6/batch-geocoder/downloads)](https://packagist.org/packages/geo6/batch-geocoder)
[![Monthly Downloads](https://poser.pugx.org/geo6/batch-geocoder/d/monthly.png)](https://packagist.org/packages/geo6/batch-geocoder)
[![Software License](https://img.shields.io/badge/license-GPL--3.0-brightgreen.svg)](LICENSE)

The "Batch Geocoder" application allows you to geocode your dataset of addresses.  
You can upload your file and the application will process it to add a location (longitude, latitude) to each address.  
The application will try to find a location using several providers (GEO-6, Geopunt, UrbIS, SPW, ...).

---

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

### Additional parameters

| Parameter name  | Type      | Description                                        |
|-----------------|-----------|----------------------------------------------------|
| title           | *string*  | Title of the application (default: batch-geocoder) |
| archives        | *boolean* | Enable archive mode (see here under)               |
| limit           | *integer* | Maximum number of records allowed                  |

All those parameters are optional.  
By default, archive mode is disabled and there is no limit of maximum number of records allowed.

#### Archive mode

If you enable archive mode, you can add `?archives` to display a listbox of existing tables : <http://localhost:8080/app/batch-geocoder/?archives>
