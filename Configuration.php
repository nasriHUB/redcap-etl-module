<?php

namespace IU\RedCapEtlModule;

class Configuration implements \JsonSerializable
{
    const REDCAP_API_URL = 'redcap_api_url';
    const DATA_SOURCE_API_TOKEN = 'data_source_api_token';

    const TRANSFORM_RULES_TEXT   = 'transform_rules_text';
    const TRANSFORM_RULES_SOURCE = 'transform_rules_source';

    const CONFIG_API_TOKEN = 'config_api_token';

    const DB_HOST = 'db_host';
    const DB_NAME = 'db_name';
    const DB_USERNAME = 'db_username';
    const DB_PASSWORD = 'db_password';

    const DB_CONNECTION = 'db_connection';
    
    const BATCH_SIZE = 'batch_size';

    private $name;
    private $properties;

    public function __construct($name)
    {
        global $project_id;

        $this->name = $name;

        $this->properties = array();
        foreach (self::getPropertyNames() as $name) {
                $this->properties[$name] = '';
        }

        # Set non-blank defaults
        $this->properties[self::REDCAP_API_URL]    = APP_PATH_WEBROOT_FULL.'api/';
        $this->properties[self::BATCH_SIZE] = 100;
        $this->properties[self::TRANSFORM_RULES_SOURCE] = '1';

        if (!empty($project_id)) {
            $sql = "select api_token from redcap_user_rights "
                    . " where project_id = {$project_id} "
                    . " and username = '".USERID."'"
                    . " and api_export = 1 "
                    ;
            $result = db_query($sql);
            if ($row = db_fetch_assoc($result)) {
                $apiToken = $row['api_token'];
                if (!empty(api_token)) {
                    $this->properties[self::DATA_SOURCE_API_TOKEN] = $apiToken;
                }
            }
        }
    }

    public function jsonSerialize()
    {
        return (object) get_object_vars($this);
    }

    public function set($properties)
    {
        #------------------------------------------------
        # Validate values
        #------------------------------------------------
        foreach (self::getPropertyNames() as $name) {
            if (array_key_exists($name, $properties)) {
                $value = $properties[$name];
                switch($name) {
                case Configuration::REDCAP_API_URL:
                    if (!empty($value) && filter_var($value, FILTER_VALIDATE_URL) === false) {
                        $message = 'Invalid REDCap API URL.';
                        throw new \Exception($message);
                    }
                }
            }
        }

        #------------------------------------------------
        # Set values
        #------------------------------------------------
        foreach (self::getPropertyNames() as $name) {
            if (array_key_exists($name, $properties)) {
                $this->properties[$name] = $properties[$name];
            } else {
                $this->properties[$name] = '';
            }
        }
        
        $dbHost = $properties[self::DB_HOST];
        $dbName = $properties[self::DB_NAME];
        
        $dbUsername = $properties[self::DB_USERNAME];
        $dbPassword = $properties[self::DB_PASSWORD];
        
        $this->properties[self::DB_CONNECTION] = 'MySQL:'.$dbHost.':'.$dbUsername.':'.$dbPassword.':'.$dbName;
    }

    public function getProperty($propertyName)
    {
        $property = $this->properties[$propertyName];
        return $property;
    }

    public function setProperty($propertyName, $value)
    {
        $this->$properties[$propertyName] = $value;
    }

    public function getProperties()
    {
        return $this->properties;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getApiUrl()
    {
        return $this->properties[self::REDCAP_API_URL];
    }

    public function setApiUrl($value)
    {
        $this->properties[self::REDCAP_API_URL] = $value;
    }


    public static function getPropertyNames()
    {
        $reflection = new \ReflectionClass(self::class);
        $properyNames = $reflection->getConstants();
        return $properyNames;
    }
}
