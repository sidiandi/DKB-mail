DKB-mail
========

Scrapes the DKB online banking and sends a PUSH message on every new transaction

This PHP script scrapes the online banking website of the DKB (Deutsche Kreditbank Berlin). After login, the transactions of all accounts are downloaded as CSV files and compared with previously downloaded CSVs. If threre's a new entry, an email is sent to the specified address.

[Simple HTML DOM](http://simplehtmldom.sourceforge.net/) is used for HTML scraping.

No mails are sent on the first run. All downloaded CSVs are stored in the data directory for later comparison.

Configuration
-------------
Rename config.php-example to config.php and enter 
* the login credentials for the DKB online banking
* The login credentials for the SMTP server

Usage
-----
This script is best run from cron in your local network, ie on a homeserver or Raspberry Pi.

### Example cron entry
This runs the script every two hours during bank business hours:

0 8,10,12,14,16,18 * * 1-6 /path/to/dkb-crawl.php 