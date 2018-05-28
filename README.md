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

```
postgresql:
  host: localhost
  port: 5432
  dbname: geocode
  user: geocode
  password: <YOURPASSWORD>
```

### Geocoding API token

Please contact [GEO-6](https://geo6.be/) to ask for your token.

```
tokens:
    geo6:
        consumer: <YOURCONSUMERID>
        secret: <YOURSECRETKEY>
```

### Additional parameters

| Parameter name  | Type      | Description                                        |
|-----------------|-----------|----------------------------------------------------|
| title           | *string*  | Title of the application (default: batch-geocoder) |
| archives        | *boolean* | Enable archive mode (see here under)               |
| limit           | *integer* | Maximum number of records allowed                   |

All those parameters are optional.  
By default, archive mode is disabled and there is no limit of maximum number of records allowed.
