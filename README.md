# Batch Geocoder

[![Latest Stable Version](https://poser.pugx.org/geo6/batch-geocoder/v/stable)](https://packagist.org/packages/geo6/batch-geocoder)
[![Total Downloads](https://poser.pugx.org/geo6/batch-geocoder/downloads)](https://packagist.org/packages/geo6/batch-geocoder)
[![Monthly Downloads](https://poser.pugx.org/geo6/batch-geocoder/d/monthly.png)](https://packagist.org/packages/geo6/batch-geocoder)
[![Software License](https://img.shields.io/badge/license-GPL--3.0-brightgreen.svg)](LICENSE)

The "Batch Geocoder" application allows you to geocode your dataset of addresses.  
You can upload your file and the application will process it to add a location (longitude, latitude) to each address.  
The application will try to find a location using several providers, you can define the list of providers used in your configuration file (see [documentation](https://github.com/geo6/batch-geocoder#providers)).

---

## Install & Configuration

The "Batch Geocoder" application requires [PostgreSQL 10.0+](https://www.postgresql.org/download/) !

To install the "Batch Geocoder" application :

    composer create-project geo6/batch-geocoder

See [`INSTALL`](https://github.com/geo6/batch-geocoder/blob/master/INSTALL.md) file for more details.

---
