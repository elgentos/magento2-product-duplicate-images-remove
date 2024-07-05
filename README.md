# Elgentos - Remove Duplicate Product images in Magento 2

This Extension allows you to find duplicate product images from your product list and from this list you can easily remove them by running a command.

## Installation

1) Go to your Magento root folder
2) Download the extension using composer:
    ```
    composer require elgentos/magento2-product-duplicate-images-remove
    ```
3) Run setup commands:

    ```
    php bin/magento setup:upgrade
    ```
   
4) Run the command:

   ```
   php bin/magento duplicate:remove
   ```
