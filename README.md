# Database Abstraction Class

Provides a simple unified interface for connecting to a bunch of different database types.  Supports php native mysqli and pgsql extensions, as well as PDO, AdoDB, and MDB2 connections as well as everything supported by PDO and AdoDB.

## Build Status and Code Analysis

Site          | Status
--------------|---------------------------
![Travis-CI](https://secure.gravatar.com/avatar/253768044712357787be0f6a3a53cc66.jpg?s=50&r=g&d=mm)     | [![Build Status](https://travis-ci.org/detain/db_abstraction.svg?branch=master)](https://travis-ci.org/detain/db_abstraction)
![CodeClimate](https://dwv6i237vhjwj.cloudfront.net/assets/cc/styles/logo/logo-1b57e6f70087e79bbd96fdada69a41df.svg)  | [![Code Climate](https://codeclimate.com/github/detain/db_abstraction/badges/gpa.svg)](https://codeclimate.com/github/detain/db_abstraction) [![Test Coverage](https://codeclimate.com/github/detain/db_abstraction/badges/coverage.svg)](https://codeclimate.com/github/detain/db_abstraction/coverage) [![Issue Count](https://codeclimate.com/github/detain/db_abstraction/badges/issue_count.svg)](https://codeclimate.com/github/detain/db_abstraction)
[![alt text](https://scrutinizer-ci.com/rt_assets/images/logo1.png "Scrutinizer")](https://scrutinizer-ci.com/)  | [![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/detain/db_abstraction/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/detain/db_abstraction/?branch=master) [![Code Coverage](https://scrutinizer-ci.com/g/detain/db_abstraction/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/detain/db_abstraction/?branch=master) [![Build Status](https://scrutinizer-ci.com/g/detain/db_abstraction/badges/build.png?b=master)](https://scrutinizer-ci.com/g/detain/db_abstraction/build-status/master)
![Codacy](http://i.is.cc/storage/GtbHeG5.png)        | [![Codacy Badge](https://api.codacy.com/project/badge/Grade/79294bb43f1f45a7865001c370a44e35)](https://www.codacy.com/app/detain/db_abstraction?utm_source=github.com&amp;utm_medium=referral&amp;utm_content=detain/db_abstraction&amp;utm_campaign=Badge_Grade) [![Codacy Badge](https://api.codacy.com/project/badge/Coverage/79294bb43f1f45a7865001c370a44e35)](https://www.codacy.com/app/detain/db_abstraction?utm_source=github.com&amp;utm_medium=referral&amp;utm_content=detain/db_abstraction&amp;utm_campaign=Badge_Coverage)
![Coveralls](http://i.is.cc/storage/Gt11ib5.png)    | [![Coverage Status](https://coveralls.io/repos/github/detain/db_abstraction/badge.svg?branch=master)](https://coveralls.io/github/detain/db_abstraction?branch=master)
![Packagist](https://secure.gravatar.com/avatar/84c2812eaae4ff958f4d00b68b576860.jpg?s=50&r=g&d=mm)     | [![Latest Stable Version](https://poser.pugx.org/detain/db_abstraction/version)](https://packagist.org/packages/detain/db_abstraction) [![Latest Unstable Version](https://poser.pugx.org/detain/db_abstraction/v/unstable)](//packagist.org/packages/detain/db_abstraction) [![License](https://poser.pugx.org/detain/db_abstraction/license)](https://packagist.org/packages/detain/db_abstraction) [![Total Downloads](https://poser.pugx.org/detain/db_abstraction/downloads)](https://packagist.org/packages/detain/db_abstraction)


## Installation

Install with composer like

```sh
composer require detain/db_abstraction
```

## License

The Database Abstraction Class class is licensed under the LGPL-v2.1 license.

