<?php

#-------------------------------------------------------
# Copyright (C) 2019 The Trustees of Indiana University
# SPDX-License-Identifier: BSD-3-Clause
#-------------------------------------------------------


use IU\REDCapETL\EtlRedCapProject;
use IU\REDCapETL\Database\DbConnectionFactory;
use IU\RedCapEtlModule\Authorization;
use IU\RedCapEtlModule\Configuration;
use IU\RedCapEtlModule\Csrf;
use IU\RedCapEtlModule\Filter;
use IU\RedCapEtlModule\Help;
use IU\RedCapEtlModule\RedCapDb;
use IU\RedCapEtlModule\RedCapEtlModule;

#-------------------------------------------------------------------------
# Only allow this page to be included in the user ETL Configure page,
# and not accessed directly
#-------------------------------------------------------------------------
if (!defined('REDCAP_ETL_MODULE')) {
    header($_SERVER["SERVER_PROTOCOL"] . " 403 Forbidden");
    exit;
}

$error = '';
$warning = '';
$success = '';

$parseResult = '';

$dbPasswordMask = "********";

try {
    #-----------------------------------------------------------
    # Check that the user has permission to access this page
    # and get the configuration if one was specified
    #-----------------------------------------------------------
    $configCheck = true;
    $configuration = $module->checkUserPagePermission(USERID, $configCheck);
    $configName = '';
    if (!empty($configuration)) {
        $configName = $configuration->getName();
        $properties = $configuration->getProperties();
    }

    #-------------------------------------------------------------------
    # Check for test mode (which should only be used for development)
    #-------------------------------------------------------------------
    $testMode = false;
    if (@file_exists(__DIR__ . '/../test-config.ini')) {
        $testMode = true;
    }

    if (array_key_exists('success', $_GET)) {
        $success = Filter::stripTags($_GET['success']);
    }

    if (array_key_exists('warning', $_GET)) {
        $warning = Filter::stripTags($_GET['warning']);
    }

    $listUrl = $module->getUrl('web/index.php');
    $configureUrl = $module->getUrl('web/configure.php');
    $selfUrl = $module->getUrl('web/configure.php');
    $generateRulesUrl = $module->getUrl('web/generate_rules.php');

    $adminConfig = $module->getAdminConfig();


    /** @var array configurations property map from property name to value */
    $properties = array();

    $redCapDb = new RedCapDb();

    if (!empty($configuration)) {
        #--------------------------------------------------------------
        # Get the API tokens for this project with export permission,
        # and the username of user whose API token should be used
        # (if any)
        #--------------------------------------------------------------
        $exportRight  = $configuration->getDataExportRight();
        $apiTokens    = $redCapDb->getApiTokens(PROJECT_ID, $exportRight);
        $apiTokenUser = $configuration->getProperty(Configuration::API_TOKEN_USERNAME);


        #-------------------------
        # Get the submit value
        #-------------------------
        $submitValue = '';
        if (array_key_exists('submitValue', $_POST)) {
            $submitValue = Filter::sanitizeButtonLabel($_POST['submitValue']);
        }

        #---------------------------------------------------------------
        # if this is a POST other than Cancel,
        # update the configuration properties with the POST values
        #---------------------------------------------------------------
        if (!empty($submitValue) && strcasecmp($submitValue, 'Cancel')) {
            if (!isset($_POST[Configuration::API_TOKEN_USERNAME])) {
                $_POST[Configuration::API_TOKEN_USERNAME] = '';
            }

            if ($_POST['autogen_before_run']) {
                $_POST[Configuration::TRANSFORM_RULES_SOURCE] = 3;
            } else {
                if (!empty($_POST[Configuration::TRANSFORM_RULES_TEXT])) {
                    $_POST[Configuration::TRANSFORM_RULES_SOURCE] = 1;
                }
            }

            # if the database password is equal to the make value, it means that it
            # wasn't changed, so unset this value (since you son't want the mask value
            # stored in the configuration
            if ($_POST[Configuration::DB_PASSWORD] === $dbPasswordMask) {
                unset($_POST[Configuration::DB_PASSWORD]);
            }
            #print("<pre>");
            #print_r($_POST);
            #print("</pre>");
            $configuration->set(Filter::stripTagsArrayRecursive($_POST));

            # If this is NOT a remote REDCap configuration, set SSL certificate verification
            # to the global value (this can only be set in the configuration for remote
            # REDCap configurations)
            $apiUrl = $configuration->getProperty(Configuration::REDCAP_API_URL);
            if (strcmp($apiUrl, $module->getRedCapApiUrl()) === 0) {
                $configuration->setProperty(Configuration::SSL_VERIFY, $adminConfig->getSslVerify());
            }
            $properties = $configuration->getProperties();
        }

        #----------------------------------------------
        # Check API token specification
        #----------------------------------------------
        $localApiUrl = $module->getRedCapApiUrl();
        $apiTokenUser = '';

        $apiUrl = $configuration->getProperty(Configuration::REDCAP_API_URL);
        if ($testMode && strcmp($apiUrl, $module->getRedCapApiUrl()) !== 0) {
            ; // Test mode, so remote REDCap is being used, so no checks can be done
            # In test mode:
            # - the REDCap API URL becomes editable for admins
            # - if the REDCap API URL is changed so that it does not match the API URL of local REDCap,
            #   the API token user is ignored
            # -
        } else {
            if (empty($configuration->getProperty(Configuration::API_TOKEN_USERNAME))) {
                # No API token user was specified, set the API token to blank
                $configuration->setProperty(Configuration::DATA_SOURCE_API_TOKEN, '');
            } else {
                $apiTokenUser = $configuration->getProperty(Configuration::API_TOKEN_USERNAME);
                # An API token user was specified
                if (!array_key_exists($apiTokenUser, $apiTokens)) {
                    $warning = 'WARNING: user "' . $apiTokenUser . '" does not'
                        . ' have a valid API token for using ETL with this project.'
                        . ' The user must have an API token with export rights,'
                        . ' have "Full Data Set" export privilege, '
                        . ' and not belong to a DAG (Data Access Group).'
                        . ' API token user reset to blank.';
                    # The API token user does not have a valid API token, so set it to blank
                    $configuration->setProperty(Configuration::API_TOKEN_USERNAME, '');
                    $configuration->setProperty(Configuration::DATA_SOURCE_API_TOKEN, '');
                } else {
                    # A valid API token user was specified, so set the API token to the
                    # value for this user
                    $configuration->setProperty(
                        Configuration::DATA_SOURCE_API_TOKEN,
                        $apiTokens[$apiTokenUser]
                    );
                }
            }
        }


        # Reset properties, since they may have been modified above
        $properties = $configuration->getProperties();

        #----------------------------------------------
        # Check non-repeating fields options
        #----------------------------------------------
        $nonRepeatingFields = false;
        if (array_key_exists('autogen_combine_non_repeating_fields', $_POST)) {
            $nonRepeatingFields = true;
        }

        $nonRepeatingFieldsTable = '';
        if (array_key_exists('autogen_non_repeating_fields_table', $_POST)) {
            $nonRepeatingFieldsTable =
                Filter::sanitizeLabel(trim($_POST['autogen_non_repeating_fields_table']));
        }

        if ($nonRepeatingFields && empty($nonRepeatingFieldsTable)) {
            $error = 'ERROR: In AUTO-GENERATE TRANSFORMATION RULES, no table name specified for combined table.';
        }

        #------------------------------------------------------
        # Process Actions
        #------------------------------------------------------
        try {
            if (strcasecmp($submitValue, 'Cancel') === 0) {
                header('Location: ' . $listUrl);
            } elseif (strcasecmp($submitValue, 'Save') === 0) {
                if (empty($warning) && empty($error)) {
                    $configuration->validate();
                    $module->setConfiguration($configuration);  // Save configuration to database
                }
            } elseif (strcasecmp($submitValue, 'Save and Exit') === 0) {
                if (empty($warning) && empty($error)) {
                    $configuration->validate();
                    $module->setConfiguration($configuration);  // Save configuration to database
                    $location = 'Location: ' . $listUrl;
                    header($location);
                }
            } elseif (strcasecmp($submitValue, 'Upload CSV file') === 0) {
                $uploadFileName = $_FILES['uploadCsvFile']['tmp_name'];
                if (empty($uploadFileName)) {
                    $error = 'ERROR: No upload transformation rules file specified.';
                } else {
                    $fileContents = file_get_contents($uploadFileName);
                    if ($fileContents === false) {
                        $error = 'ERROR: Unable to upload transformation rules file "'
                            . $_FILES['uploadCsvFile']['tmp_name'] . '"';
                    } else {
                        $properties[Configuration::TRANSFORM_RULES_TEXT] = $fileContents;
                    }
                }
            } elseif (strcasecmp($submitValue, 'Download CSV file') === 0) {
                ;
                # This is handled in including file now, because it needs to be
                # done before any output has been generated. So, this condition
                # should never be reached.
            } elseif (strcasecmp($submitValue, 'Auto-Generate') === 0) {
                $formCompleteFields = false;
#print "\n\n_POST is \n\n";
#print_r ($_POST);
#print "\n\n";

                if (array_key_exists('autogen_include_complete_fields', $_POST)) {
                    $formCompleteFields = true;
                }

                $dagFields = false;
                if (array_key_exists('autogen_include_dag_fields', $_POST)) {
                    $dagFields = true;
                }

                $fileFields = false;
                if (array_key_exists('autogen_include_file_fields', $_POST)) {
                    $fileFields = true;
                }

                $surveyFields = false;
                if (array_key_exists('autogen_include_survey_fields', $_POST)) {
                    $surveyFields = true;
                }

                $notesFields = false;
                if (array_key_exists('autogen_remove_notes_fields', $_POST)) {
                    $notesFields = true;
                }

                $identifierFields = false;
                if (array_key_exists('autogen_remove_identifier_fields', $_POST)) {
                    $identifierFields = true;
                }

                #$nonRepeatingFields was specified earlier in this code

                #$nonRepeatingFieldsTable was specified earlier in this code

                $apiUrl    = $configuration->getProperty(Configuration::REDCAP_API_URL);
                $dataToken = $configuration->getProperty(Configuration::DATA_SOURCE_API_TOKEN);

                if (empty($apiUrl)) {
                    $error = 'ERROR: No REDCap API URL specified.';
                } elseif (empty($dataToken)) {
                    $error = 'ERROR: No data source API token information specified.';
                } elseif ($nonRepeatingFields && empty($nonRepeatingFieldsTable)) {
                    $error = 'ERROR: In AUTO-GENERATE TRANSFORMATION RULES, ';
                    $error .= 'no table name specified for combined table.';
                } else {
                    $existingRulesText = $properties[Configuration::TRANSFORM_RULES_TEXT];
                    $areExistingRules = false;
                    if (!empty($existingRulesText)) {
                        # WARN that existing rules will be overwritten
                        # ...
                        $areExistingRules = true;
                        #echo
                        #"<script>\n"
                        #.'$("#rules-overwrite-dialog").dialog("open");'."\n"
                        #."</script>\n"
                        #;
                    }


                    $sslVerify  = $adminConfig->getSslVerify();
                    $caCertFile = null;
                    #$caCertFile = $adminConfig->getCaCertFile();

                    if ($testMode) {
                        # If module is in test mode, override the system-wide SSL verify flag
                        # and certificate authority certificate file with the configuration
                        # specific ones
                        $sslVerify  = $configuration->getProperty(Configuration::SSL_VERIFY);
                        #$caCertFile = $configuration->getProperty(Configuration::CA_CERT_FILE);
                    }

                    #if ($testMode && strcmp($apiUrl, $module->getRedCapApiUrl()) !== 0) {
                    #    # If in test mode, and a remote API URL was used, use an API token to get the data project
                    #    # for rules generation
                    #    $dataProject = new \IU\REDCapETL\EtlRedCapProject(
                    #        $apiUrl,
                    #        $dataToken,
                    #        $sslVerify,
                    #        $caCertFile
                    #    );
                    #} else {
                        # Non-remote API URL, create a data project for rules generation
                        # that uses REDCap's developer methods
                        #$dataProject = new \IU\RedCapEtlModule\EtlExtRedCapProject(
                        $dataProject = new \IU\REDCapETL\EtlRedCapProject(
                            $apiUrl,
                            $dataToken,
                            $sslVerify,
                            $caCertFile
                        );
                    #}

                    $rulesGenerator = new \IU\REDCapETL\RulesGenerator();

                    $rulesText = $rulesGenerator->generate(
                        $dataProject,
                        $formCompleteFields,
                        $dagFields,
                        $fileFields,
                        $surveyFields,
                        $notesFields,
                        $identifierFields,
                        $nonRepeatingFields,
                        $nonRepeatingFieldsTable
                    );
                    $properties[Configuration::TRANSFORM_RULES_TEXT] = $rulesText;
                    #print "$rulesText\n";
                }
            } elseif (strcasecmp($submitValue, 'Check Rules') === 0) {
                // Code to check current transformation rules; to be completed...
                $apiUrl    = $configuration->getProperty(Configuration::REDCAP_API_URL);
                $dataToken = $configuration->getProperty(Configuration::DATA_SOURCE_API_TOKEN);
                $sslVerify  = $adminConfig->getSslVerify();
                $caCertFile = null;
                $dataProject = new \IU\REDCapETL\EtlRedCapProject($apiUrl, $dataToken, $sslVerify, $caCertFile);
                #$dataProject =
                #    new \IU\RedCapEtlModule\EtlExtRedCapProject($apiUrl, $dataToken, $sslVerify, $caCertFile);

                $logger = new \IU\REDCapETL\Logger('rules-check');
                $logger->setOn(false);

                $checkProperties = $configuration->getPropertiesArray();
                if ($sslVerify) {
                    $checkProperties[Configuration::SSL_VERIFY] = 'true';
                }
                $checkProperties[Configuration::EMAIL_ERRORS] = false;
                $checkProperties[Configuration::EMAIL_SUMMARY] = false;

                #$checkConfiguration = new \IU\REDCapETL\Configuration($logger, $checkProperties);
                $checkConfiguration = new \IU\REDCapETL\TaskConfig();
                $checkConfiguration->set($logger, $checkProperties);

                $schemaGenerator = new \IU\REDCapETL\SchemaGenerator($dataProject, $checkConfiguration, $logger);
                $rulesText = $checkConfiguration->getProperty(Configuration::TRANSFORM_RULES_TEXT);
                list($schema, $parseResult) = $schemaGenerator->generateSchema($rulesText);
            }
        } catch (\Exception $exception) {
            $error = 'ERROR: ' . $exception->getMessage();
        }
    }  // END - if configuration is not empty
} catch (\Exception $exception) {
    $error = 'ERROR: ' . $exception->getMessage();
}

$module->renderMessages($error, $warning, $success);
?>

<script>
    // Help dialog events
    $(document).ready(function() {

        // Blank out db password input field so stupid browsers will not prompt to save password
        $(window).bind('beforeunload', function(){
            // This seems to work for all cases except form submit
            $("#dbPassword").val("");
        });

        $("#dbPassword").focus(function(event){
            $("#dbPassword").val("");
        });


        $( function() {
            
            $('#db_primary_keys').click(function () {
                if (this.checked) {
                    $("#db_foreign_keys").prop("disabled", false);
                } else {
                    $("#db_foreign_keys").prop("checked", false);
                    $("#db_foreign_keys").prop("disabled", true);
                }
            });
            
            $('#auto-generate-rules-help-link').click(function () {
                $('#auto-generate-rules-help').dialog({dialogClass: 'redcap-etl-help', width: 400, maxHeight: 440})
                    .dialog('widget').position({my: 'left top', at: 'right+370 top-140', of: $(this)})
                    ;
                return false;
            });
            $('#batch-size-help-link').click(function () {
                $('#batch-size-help').dialog({dialogClass: 'redcap-etl-help', width: 400, maxHeight: 440})
                    .dialog('widget').position({my: 'left top', at: 'right+20 top', of: $(this)})
                    ;
                return false;
            });
            $('#database-event-log-table-help-link').click(function () {
                $('#database-event-log-table-help').dialog({dialogClass: 'redcap-etl-help'})
                    .dialog('widget').position({my: 'left top', at: 'right+20 top', of: $(this)})
                    ;
                return false;
            });             
            $('#database-log-table-help-link').click(function () {
                $('#database-log-table-help').dialog({dialogClass: 'redcap-etl-help'})
                    .dialog('widget').position({my: 'left top', at: 'right+20 top', of: $(this)})
                    ;
                return false;
            });            
            $('#database-logging-help-link').click(function () {
                $('#database-logging-help').dialog({dialogClass: 'redcap-etl-help'})
                    .dialog('widget').position({my: 'left top', at: 'right+20 top', of: $(this)})
                    ;
                return false;
            });
            $('#email-notifications-help-link').click(function () {
                $('#email-notifications-help').dialog({dialogClass: 'redcap-etl-help', width: 400, maxHeight: 440})
                    .dialog('widget').position({my: 'left top', at: 'right-20 top', of: $(this)})
                    ;
                return false;
            });            
            $('#extract-settings-help-link').click(function () {
                $('#extract-settings-help').dialog({dialogClass: 'redcap-etl-help', width: 500, maxHeight: 540})
                    .dialog('widget').position({my: 'left+30 top', at: 'right top-140', of: $(this)})
                    ;
                return false;
            });
            $('#ignore-empty-incomplete-forms-help-link').click(function () {
                $('#ignore-empty-incomplete-forms-help')
                    .dialog({dialogClass: 'redcap-etl-help', width: 400, maxHeight: 440})
                    .dialog('widget').position({my: 'left top', at: 'right+20 top', of: $(this)})
                    ;
                return false;
            });
            $('#labels-help-link').click(function () {
                $('#labels-help').dialog({dialogClass: 'redcap-etl-help', width: 600, maxHeight: 440})
                    .dialog('widget').position({my: 'left top', at: 'right+20 top', of: $(this)})
                    ;
                return false;
            });
            $('#database-keys-help-link').click(function () {
                $('#database-keys-help').dialog({dialogClass: 'redcap-etl-help', width: 400, maxHeight: 440})
                    .dialog('widget').position({my: 'left top', at: 'right+20 top', of: $(this)})
                    ;
                return false;
            });            
            $('#load-settings-help-link').click(function () {
                $('#load-settings-help').dialog({dialogClass: 'redcap-etl-help', width: 500, maxHeight: 440})
                    .dialog('widget').position({my: 'left top', at: 'right+20 top', of: $(this)})
                    ;
                return false;
            });
            $('#pre-processing-sql-help-link').click(function () {
                $('#pre-processing-sql-help').dialog({dialogClass: 'redcap-etl-help', width: 400, maxHeight: 400})
                    .dialog('widget').position({my: 'left top', at: 'right+10 top', of: $(this)})
                    ;
                return false;
            });  
            $('#post-processing-sql-help-link').click(function () {
                $('#post-processing-sql-help').dialog({dialogClass: 'redcap-etl-help', width: 400, maxHeight: 400})
                    .dialog('widget').position({my: 'left top', at: 'right+10 top', of: $(this)})
                    ;
                return false;
            });            
            $('#table-name-prefix-help-link').click(function () {
                $('#table-name-prefix-help').dialog({dialogClass: 'redcap-etl-help'})
                    .dialog('widget').position({my: 'left top', at: 'right+20 top', of: $(this)})
                    ;
                return false;
            });
        });
        
        // Database schema display
        $(function() {
        $("select[name=<?php echo Configuration::DB_TYPE;?>]").change(function() {
            var value = $(this).val();
            if (value == "<?php echo DbConnectionFactory::DBTYPE_POSTGRESQL; ?>") {
                $("#dbSchemaRow").show();
            } else {
                $("#dbSchemaRow").hide();
            }
        });
            
    });
    

});
</script>

<div class="projhdr"> 
    <?php
    if ($testMode) {
        echo '<span style="color: blue;">[TEST MODE]</span>';
    }
    ?>
</div>


<?php
if (empty($configuration)) {
    ; // Don't display any page content
} else {
    ?>


<!-- Rules overwrite dialog -->
<div id="rules-overwrite-dialog" style="display:none;" title="Overwrite transformation rules?">
  Test...
</div>

<!-- generate rules -->

<!-- <button type="button" id="api-token-button">...</button> -->
<script>
$(function() {
    $("#rules-overwrite-dialog").dialog({
        autoOpen: false
    });
    $("#api-token-button").click(function() {
        $("#dialog").dialog("open");
    });
});
</script>


<script>
// Show/hide API Token
$(function() {
    $("#showApiToken").change(function() {
        var newType = 'password';
        if ($(this).is(':checked')) {
            newType = 'text';
        }
        $("#apiToken").each(function(){
            $("<input type='" + newType + "'>")
                .attr({ id: this.id, name: this.name, value: this.value, size: this.size, style: this.style })
                .insertBefore(this);
        }).remove();       
    })
});    


</script>


<!-- ====================================
Configuration form
===================================== -->
<form id="configForm" action="<?php echo $selfUrl;?>" method="post"
    enctype="multipart/form-data" style="margin-top: 17px;" autocomplete="off">

    <input type="hidden" id="submitFlag" name="submitFlag" value="false" />

    <input type="hidden" name="configName"
        value="<?php echo Filter::escapeForHtmlAttribute($configName); ?>" />
    
    <input type="hidden" name="<?php echo Configuration::CONFIG_API_TOKEN; ?>"
           value="<?php echo Filter::escapeForHtmlAttribute($properties[Configuration::CONFIG_API_TOKEN]); ?>" />
    
    <input type="hidden" name="<?php echo Configuration::TRANSFORM_RULES_SOURCE; ?>"
           value="<?php echo Filter::escapeForHtmlAttribute($properties[Configuration::TRANSFORM_RULES_SOURCE]); ?>" />
     
    <input type="hidden" name="configType" value="task">

    <!--<div style="padding: 10px; border: 1px solid #ccc; background-color: #f0f0f0;"> -->

    <fieldset class="config">
        <legend>Extract Settings</legend>

        <table>
            <tbody style="padding: 20px;">

                <?php if ($module->isSuperUser()) { ?>
                <tr>
                    <td>REDCap API URL</td>
                    <?php
                    if ($testMode && $module->isSuperUser()) { # make API URL editable
                        $displayApiUrl = Filter::escapeForHtmlAttribute($properties[Configuration::REDCAP_API_URL]);
                        ?>
                    <td>
                        <input type="text" size="60" 
                            value="<?php echo $displayApiUrl;?>"
                            name="<?php echo Configuration::REDCAP_API_URL?>" />
                    </td>
                    <?php } else { ?>
                    <td>
                        <div style="border: 1px solid #AAAAAA; margin: 4px 0px; padding: 4px; border-radius: 4px;">
                        <?php echo Filter::escapeForHtml($properties[Configuration::REDCAP_API_URL]); ?>
                        </div>
                    </td>
                    <?php } ?>
                </tr>
                <?php } // End if super user ?>

                <?php if ($testMode && $module->isSuperUser()) { # make API URL editable ?>
                <tr>
                    <td>
                    SSL certificate verification&nbsp;
                    </td>
                    <td>
                        <?php
                        $value = '';
                        $checked = '';
                        if ($properties[Configuration::SSL_VERIFY]) {
                            $checked = ' checked ';
                            $value = ' value="true" ';
                        }
                        ?>
                        <input type="checkbox" name="<?php echo Configuration::SSL_VERIFY;?>"
                        <?php echo $checked;?>
                        <?php echo $value;?> >
                    </td>
                </tr>
                    
                <tr>
                    <td>
                    API token
                    </td>
                    <td>
                        <?php
                        $apiToken = $properties[Configuration::DATA_SOURCE_API_TOKEN];
                        ?>
                        <input type="password" size="34"
                            value="<?php echo Filter::escapeForHtmlAttribute($apiToken);?>"
                            name="<?php echo Configuration::DATA_SOURCE_API_TOKEN;?>" id="apiToken"
                            autocomplete="off"/>
                        <input type="checkbox" id="showApiToken" style="vertical-align: middle; margin: 0;">
                        <span style="vertical-align: middle;">Show</span>
                    </td>
                    <td>
                        <div id="dialog" style="display:none;" title="Data Source API Token">
                        Test...
                        </div>
                        <!-- <button type="button" id="api-token-button">...</button> -->
                        <script>
                        $(function() {
                            $("#dialog").dialog({
                                autoOpen: false
                            });
                            $("#api-token-button").click(function() {
                                $("#dialog").dialog("open");
                            });
                        });
                        </script>
                    </td>
                </tr>
                <?php } # End - if test mode and super user (admin) ?>
                
                <tr class="localRow" <?php echo $localRowStyle; ?> >
                    <td>API Token - use token of user&nbsp;</td>
                    <td>
                        <select name="<?php echo Configuration::API_TOKEN_USERNAME;?>">
                            <?php

                            echo '<option value=""></option>' . "\n";
                            foreach ($apiTokens as $username => $apiToken) {
                                $selected = '';
                                if (strcasecmp($username, $apiTokenUser) === 0) {
                                    $selected = 'selected';
                                }
                                echo '<option ' . $selected . ' value="'
                                    . Filter::escapeForHtmlAttribute($username) . '">'
                                    . Filter::escapeForHtml($username) . '</option>' . "\n";
                            }
                            ?>
                        </select>
                    </td>
                    <td>
                        <a href="#" id="extract-settings-help-link" class="etl-help" style="margin-left: 10px;">?</a>
                        <div id="extract-settings-help" title="Extract Settings" style="display: none; clear: both;">
                            <?php echo Help::getHelpWithPageLink('extract-settings', $module); ?>
                        </div> 
                    </td>
                </tr>
                
                <?php #} # END else ?>



                <tr>
                    <td>
                    API token status
                    </td>
                    <td>
                        <?php

                        echo "<div style=\"border: 1px solid #AAAAAA; margin-bottom: 4px;"
                            . " padding: 4px; border-radius: 4px;\">\n";


                        $apiUrl = $module->getRedCapApiUrl();
                        #--------------------------------------------------------------------------------------------
                        # If the configurations API URL doesn't match the project's API URL -
                        # this case should only be possible for admins in test mode and indicates
                        # admin is entering information token information for a remote system
                        #--------------------------------------------------------------------------------------------
                        if (strcasecmp(trim($properties[Configuration::REDCAP_API_URL]), trim($apiUrl)) !== 0) {
                            echo '<span style="color: navy; font-weight: bold;">?</span>&nbsp;&nbsp;';
                            if (empty($properties[Configuration::DATA_SOURCE_API_TOKEN])) {
                                echo "No REDCap API token specified.";
                            } elseif (empty($properties[Configuration::REDCAP_API_URL])) {
                                echo "No REDCap API URL specified.";
                            } else {
                                echo "Non-local REDCap API URL specified - no API token information available.";
                            }
                        } else {
                            if (count($apiTokens) < 1) {
                                echo '<img alt="X" style="color: red; font-weight: bold;" src='
                                    . APP_PATH_IMAGES . 'cross.png>&nbsp;&nbsp;';
                                echo "There are no API tokens for this project that have"
                                    . " the same data export rights as this configuration."
                                    . "<br /><br />"
                                    . "An API token needs to be requested "
                                    . " by a user whose data export rights matches those of the configuration.";
                            } elseif (empty($apiTokenUser)) {
                                echo '<img alt="X" style="color: red; font-weight: bold;" src='
                                    . APP_PATH_IMAGES . 'cross.png>&nbsp;&nbsp;';
                                echo "No user's API token has been selected for this project."
                                    . "<br /><br />"
                                    . "You need to select an API token user"
                                    . " (whose API token will be used to access REDCap).";
                            } else {
                                # If there is an API token and it has export permission
                                echo '<img alt="OK" style="color: green; font-weight: bold;" src='
                                    . APP_PATH_IMAGES . 'tick.png>&nbsp;&nbsp;';
                                echo 'The API token for user "' . Filter::escapeForHtml($apiTokenUser) . '"'
                                    . ', which has export permission,'
                                    . ' has been selected.';
                            }
                        }

                        echo "</div>\n";
                        ?>
                    </td>
                </tr>

                <tr>
                    <td>
                        <label for="<?php echo Configuration::EXTRACT_FILTER_LOGIC;?>">Extract Filter Logic</label>
                        <br/>
                        (optional)
                    </td>
                    <td>
                        <input type="text" size="72" name="<?php echo Configuration::EXTRACT_FILTER_LOGIC;?>"
                            id="<?php echo Configuration::EXTRACT_FILTER_LOGIC;?>"
                            value="<?php
                                   echo Filter::escapeForHtmlAttribute(
                                       $properties[Configuration::EXTRACT_FILTER_LOGIC]
                                   );
                                    ?>">
                    </td>

                </tr>
            </tbody>
        </table>
    </fieldset>

    <fieldset class="config">
        <legend>Transform Settings</legend>
        <table>
            <tbody style="padding: 20px;">
                <!-- TRANSFORMATION RULES -->
                <tr>
                    <td style="padding-right: 1em;">Transformation rules</td>
                    <td>
                        <?php
                        $rules = $properties[Configuration::TRANSFORM_RULES_TEXT];
                        $rulesName = Configuration::TRANSFORM_RULES_TEXT;
                        ?>
                        <textarea rows="30" cols="70"
                            style="margin-top: 4px; margin-bottom: 4px;"
                            name="<?php echo $rulesName;?>"><?php echo Filter::escapeForHtml($rules);?></textarea>
                    </td>
                    <td>
                        <div>
                            <b>AUTO-GENERATE TRANSFORMATION RULES</b>
                            <a href="#" id="auto-generate-rules-help-link"
                                class="etl-help" style="margin-left: 10px; float: right;">?</a>
                            <div id="auto-generate-rules-help" title="Auto-Generate Transformation Rules"
                                style="display: none; clear: both;">
                                <?php echo Help::getHelpWithPageLink('auto-generate-rules', $module); ?>
                            </div>
                            <br />
                            <br />
                            Include:<br />

                            <?php
                            $checked = '';
                            if ($properties[Configuration::AUTOGEN_INCLUDE_DAG_FIELDS]) {
                                $checked = ' checked ';
                            }
                            ?>
                            <input type="checkbox" 
                             name="<?php echo Configuration::AUTOGEN_INCLUDE_DAG_FIELDS;?>"

                                <?php echo $checked;?> 
                              style="vertical-align: middle; margin: 0;">                    
                            Data Access Group Fields <br/>


                            <?php
                            $checked = '';
                            if ($properties[Configuration::AUTOGEN_INCLUDE_FILE_FIELDS]) {
                                $checked = ' checked ';
                            }
                            ?>                            
                            <input type="checkbox" 
                             name="<?php echo Configuration::AUTOGEN_INCLUDE_FILE_FIELDS;?>"
                              value="true"
                                <?php echo $checked;?> 
                              style="vertical-align: middle; margin: 0;">                    
                            File Fields <br/>
                            
                            <?php
                            $checked = '';
                            if ($properties[Configuration::AUTOGEN_INCLUDE_COMPLETE_FIELDS]) {
                                $checked = ' checked ';
                            }
                            ?>
                            <input type="checkbox" 
                             name="<?php echo Configuration::AUTOGEN_INCLUDE_COMPLETE_FIELDS;?>"
                              value="true"
                                <?php echo $checked;?> 
                              style="vertical-align: middle; margin: 0;">                    
                            Form Complete Fields <br/>

                            <?php
                            $checked = '';
                            if ($properties[Configuration::AUTOGEN_INCLUDE_SURVEY_FIELDS]) {
                                $checked = ' checked ';
                            }
                            ?>
                            <input type="checkbox" 
                             name="<?php echo Configuration::AUTOGEN_INCLUDE_SURVEY_FIELDS;?>"
                              value="true"
                                <?php echo $checked;?> 
                              style="vertical-align: middle; margin: 0;">                    
                            Survey Fields <br/>

                            <br />
                            Remove: <br />
                            <?php
                            $checked = '';
                            if ($properties[Configuration::AUTOGEN_REMOVE_NOTES_FIELDS]) {
                                $checked = ' checked ';
                            }
                            ?>
                            <input type="checkbox" 
                             name="<?php echo Configuration::AUTOGEN_REMOVE_NOTES_FIELDS;?>"
                              value="true"
                                <?php echo $checked;?> 
                              style="vertical-align: middle; margin: 0;">                    
                            Notes Fields <br/>

                            
                            <?php
                            $checked = '';
                            if ($properties[Configuration::AUTOGEN_REMOVE_IDENTIFIER_FIELDS]) {
                                $checked = ' checked ';
                            }
                            ?>
                            <input type="checkbox" 
                             name="<?php echo Configuration::AUTOGEN_REMOVE_IDENTIFIER_FIELDS;?>"
                              value="true"
                                <?php echo $checked;?> 
                              style="vertical-align: middle; margin: 0;">                    
                            Identifier Fields <br/>


                            <br />
                            Non-repeating fields (Non-longitudinal studies only)<br />

                            <?php
                            $checked = '';
                            if ($properties[Configuration::AUTOGEN_COMBINE_NON_REPEATING_FIELDS]) {
                                $checked = ' checked ';
                            }
                            ?>
                            <input type="checkbox" 
                             name="<?php echo Configuration::AUTOGEN_COMBINE_NON_REPEATING_FIELDS;?>"
                              value="true"
                                <?php echo $checked;?> 
                              style="vertical-align: middle; margin: 0;">                    
                            Combine all non-repeating fields into one table<br/>

                            Table name to use:<br />
                            <input type="text" name="<?php echo Configuration::AUTOGEN_NON_REPEATING_FIELDS_TABLE;?>"
                            value="<?php
                                   echo Filter::escapeForHtmlAttribute(
                                       $properties[Configuration::AUTOGEN_NON_REPEATING_FIELDS_TABLE]
                                   );
                                    ?>">
                        <br /><br />                          
                        <input type="submit" name="submitValue" value="Auto-Generate">
                        </div>


                        <br />
                        <?php
                            $checked = '';
                        if ($properties[Configuration::TRANSFORM_RULES_SOURCE] == 3) {
                            $checked = ' checked ';
                        }
                        ?>
                        <input type="checkbox" 
                            name="autogen_before_run"
                            value="true"
                            <?php echo $checked;?> 
                            style="vertical-align: middle; margin 0;">
                        Auto-generate new rules before each run <br/>
                        

                        <hr style="margin: 7px 0px;"/>
                        <div>
                            <button type="submit" value="Upload CSV file"
                                    name="submitValue" style="vertical-align: middle;">
                                <img src="<?php echo APP_PATH_IMAGES . 'csv.gif';?>" alt=""> Upload CSV file
                            </button>
                            <input type="file" name="uploadCsvFile" id="uploadCsvFile" style="display: inline;">
                        </div>

                        <hr style="margin: 7px 0px;"/>
                        <div>
                            <button type="submit" value="Download CSV file" name="submitValue">
                                <img src="<?php echo APP_PATH_IMAGES . 'csv.gif';?>" alt=""
                                    style="vertical-align: middle;">
                                <span  style="vertical-align: middle;"> Download CSV file</span>

                            </button>
                        </div>
                        <hr style="margin: 7px 0px;"/>
                        <div>
                            <button type="submit" id="check-rules-button" value="Check Rules" name="submitValue">
                                <div>
                                <span class="fas fa-check-circle etl-rules-check-icon" 
                                    aria-hidden="true" style="vertical-align: middle;"></span>
                                <span>Check Rules</span>
                                </div>
                            </button>
                        </div>
                        <hr style="margin: 7px 0px;"/>           
                        <div>
                            <a href="<?php echo $module->getUrl('web/transformation_rules.php');?>" target="_blank">
                                <i class="fas fa-book"></i>
                                Transformation Rules Guide
                            </a>
                        </div>
                    </td>
                </tr>                
            </tbody>
        </table>
    </fieldset>
    
    <fieldset class="config">
        <legend>Load Settings</legend>
       
        <table>
            <tbody style="padding: 20px;">
                <!-- DATABASE TYPE -->
                <tr>
                    <td>Database type</td>
                    <td>
                        <select name="<?php echo Configuration::DB_TYPE;?>">
                            <?php
                            # MySQL database type option
                            $dbType = DbConnectionFactory::DBTYPE_MYSQL;
                            $selected = '';
                            if (empty($properties) || $properties[Configuration::DB_TYPE] === $dbType) {
                                $selected = ' selected ';
                            }
                            ?>
                            <option value="<?php echo $dbType; ?>" <?php echo $selected; ?> >MySQL</option>

                            <?php
                            # PostgreSQL database type option
                            $dbType = DbConnectionFactory::DBTYPE_POSTGRESQL;
                            $selected = '';
                            if ($properties[Configuration::DB_TYPE] === $dbType) {
                                $selected = ' selected ';
                            }
                            ?>
                            <option value="<?php echo $dbType; ?>" <?php echo $selected; ?> >PostgreSQL</option>

                            <?php
                            # SQL Server database type option
                            $dbType = DbConnectionFactory::DBTYPE_SQLSERVER;
                            $selected = '';
                            if ($properties[Configuration::DB_TYPE] === $dbType) {
                                $selected = ' selected ';
                            }
                            ?>
                            <option value="<?php echo $dbType; ?>" <?php echo $selected; ?> >SQL Server</option>
                        </select>
                    </td>
                    <td>
                        <a href="#" id="load-settings-help-link" class="etl-help" title="help">?</a>
                        <div id="load-settings-help" title="Load Settings" style="display: none; clear: both;">
                            <?php echo Help::getHelpWithPageLink('load-settings', $module); ?>
                        </div> 
                    </td>
                </tr>


                <!-- DATABASE HOST -->
                <tr>
                    <td>Database host</td>
                    <td><input type="text" name="<?php echo Configuration::DB_HOST;?>"
                        value="<?php echo Filter::escapeForHtmlAttribute($properties[Configuration::DB_HOST])?>"/>
                    </td>
                </tr>

                <!-- DATABASE PORT NUMBER -->
                <tr>
                    <td style="padding-right: 1em;">Database port number</td>
                    <td><input type="text" name="<?php echo Configuration::DB_PORT;?>"
                        value="<?php echo Filter::escapeForHtmlAttribute($properties[Configuration::DB_PORT])?>"/>
                    </td>
                </tr>

                <!-- DATABASE NAME -->
                <tr>
                    <td>Database name</td>
                    <td><input type="text" name="<?php echo Configuration::DB_NAME;?>"
                        value="<?php echo Filter::escapeForHtmlAttribute($properties[Configuration::DB_NAME])?>"></td>
                </tr>
                
                <?php
                $dbSchemaStyle = '';
                if ($properties[Configuration::DB_TYPE] !== DbConnectionFactory::DBTYPE_POSTGRESQL) {
                    $dbSchemaStyle = ' style="display: none;" ';
                }
                ?>

                <!-- DATABASE SCHEMA -->
                <tr id="dbSchemaRow" <?php echo $dbSchemaStyle; ?> >
                    <td>Database schema</td>
                    <td><input type="text" name="<?php echo Configuration::DB_SCHEMA;?>"
                        value="<?php echo Filter::escapeForHtmlAttribute($properties[Configuration::DB_SCHEMA])?>"></td>
                </tr>

                <!-- DATABASE USERNAME -->
                <tr>
                    <td style="padding-right: 1em;">Database username</td>
                    <td><input type="text" name="<?php echo Configuration::DB_USERNAME;?>"
                        value="<?php echo Filter::escapeForHtmlAttribute($properties[Configuration::DB_USERNAME]);?>"/>
                    </td>
                </tr>

                <!-- DATABASE PASSWORD -->
                <tr>
                    <td style="padding-right: 1em;">Database password</td>
                    <td>
                        <?php
                        $dbPassword = $properties[Configuration::DB_PASSWORD];
                        if ($dbPassword == null || trim($dbPassword) === '') {
                            $dbPassword = '';
                        } else {
                            $dbPassword = $dbPasswordMask;
                        }
                        ?>
                        <input type="password" name="<?php echo Configuration::DB_PASSWORD;?>"
                            value="<?php echo Filter::escapeForHtmlAttribute($dbPassword); ?>"
                            id="dbPassword" autocomplete="off"/>
                    </td>
                </tr>
            </tbody>
        </table>
    </fieldset>
    
    <fieldset class="config">
        <legend>Processing Settings</legend>
        <table>
            <tbody style="padding: 20px;">    
                <!-- BATCH SIZE -->
                <tr>
                    <td>Batch size</td>
                    <td><input type="text" name="<?php echo Configuration::BATCH_SIZE;?>"
                        value="<?php echo Filter::escapeForHtml($properties[Configuration::BATCH_SIZE]);?>"/>
                        <a href="#" id="batch-size-help-link" class="etl-help">?</a>
                        <div id="batch-size-help" title="Batch Size" style="display: none;">
                            <?php echo Help::getHelpWithPageLink('batch-size', $module); ?>
                        </div>
                    </td>
                </tr>
                
                <!-- IGNORE EMPTY INCOMPLETE FORMS -->
                <tr>
                    <td>Ignore empty <br/> incomplete forms &nbsp; </td>
                    <td>
                        <?php
                        $checked = '';
                        if ($properties[Configuration::IGNORE_EMPTY_INCOMPLETE_FORMS]) {
                            $checked = ' checked ';
                        }
                        ?>
                        <input type="checkbox" name="<?php echo Configuration::IGNORE_EMPTY_INCOMPLETE_FORMS;?>"
                            id="<?php echo Configuration::IGNORE_EMPTY_INCOMPLETE_FORMS;?>" value="true"
                            <?php echo $checked;?> style="vertical-align: middle; margin: 0;">    
                        <a href="#" id="ignore-empty-incomplete-forms-help-link"
                           class="etl-help" style="margin-left: 1em;">?</a>
                        <div id="ignore-empty-incomplete-forms-help"
                             title="Ignore Empty Incomplete Forms" style="display: none;">
                            <?php echo Help::getHelpWithPageLink('ignore-empty-incomplete-forms', $module); ?>
                        </div>
                    </td>                    
                </tr>
            </tbody>
        </table>
                <fieldset class="config-nested">
                <legend>Database Tables</legend>
                        <table>
            <tbody>
          
                <!-- TABLE NAME PREFIX -->
                <tr>
                    <td style="padding-right: 1em;">
                        <label for="<?php echo Configuration::TABLE_PREFIX;?>">Table name prefix</label>
                    </td>
                    <td><input type="text" name="<?php echo Configuration::TABLE_PREFIX;?>"
                        id="<?php echo Configuration::TABLE_PREFIX;?>"
                        value="<?php echo Filter::escapeForHtml($properties[Configuration::TABLE_PREFIX]);?>"/>
                    </td>
                    <td>
                        <a href="#" id="table-name-prefix-help-link" class="etl-help" style="margin-left: 1em;">?</a>
                        <div id="table-name-prefix-help" title="Table Name Prefix" style="display: none;">
                            <?php echo Help::getHelpWithPageLink('table-name-prefix', $module); ?>
                        </div>
                    </td>
                </tr>
     
                <tr>
                    <td>&nbsp;</td>
                </tr>
     
                <!-- LABEL VIEWS -->
                <tr>
                    <td style="padding-right: 1em;">Label views</td>
                    <td>
                        <?php
                        $checked = '';
                        if ($properties[Configuration::LABEL_VIEWS]) {
                            $checked = ' checked ';
                        }
                        ?>
                        <input type="checkbox" name="<?php echo Configuration::LABEL_VIEWS;?>"
                               value="true" <?php echo $checked;?>
                               style="vertical-align: middle; margin: 0;"
                        />
                    </td>
                    <td>
                        <a href="#" id="labels-help-link" class="etl-help" style="margin-left: 1em;">?</a>
                        <div id="labels-help" title="Labels" style="display: none;">
                            <?php echo Help::getHelpWithPageLink('labels', $module); ?>
                        </div>
                    </td>
                </tr>
                
                <!-- LABEL VIEW SUFFIX -->
                <tr>
                    <td style="padding-right: 1em;">Label view suffix</td>
                    <td><input type="text" name="<?php echo Configuration::LABEL_VIEW_SUFFIX;?>"
                        value="<?php echo Filter::escapeForHtml($properties[Configuration::LABEL_VIEW_SUFFIX]);?>"/>
                    </td>
                </tr>
                
                <!-- LABEL FIELD SUFFIX -->
                <tr>
                    <td style="padding-right: 1em;">Label field suffix</td>
                    <td><input type="text" name="<?php echo Configuration::LABEL_FIELD_SUFFIX;?>"
                        value="<?php echo Filter::escapeForHtml($properties[Configuration::LABEL_FIELD_SUFFIX]);?>"/>
                    </td>
                </tr>
                
                <tr>
                    <td>&nbsp;</td>
                </tr>
     
                <!-- PRIMARY KEYS -->
                <tr>
                    <td style="padding-right: 1em;">Primary Keys</td>
                    <td>
                        <?php
                        $checked = '';
                        if ($properties[Configuration::DB_PRIMARY_KEYS]) {
                            $checked = ' checked ';
                        }
                        ?>
                        <input type="checkbox" name="<?php echo Configuration::DB_PRIMARY_KEYS;?>"
                            id="db_primary_keys" value="true"
                            <?php echo $checked;?> style="vertical-align: middle; margin: 0;">    
                    </td>
                    <td>
                        <a href="#" id="database-keys-help-link" class="etl-help" style="margin-left: 1em;">?</a>
                        <div id="database-keys-help" title="Database Keys" style="display: none;">
                            <?php echo Help::getHelpWithPageLink('database-keys', $module); ?>
                        </div>
                    </td>
                </tr>

                <!-- FOREIGN KEYS -->
                <tr>
                    <td style="padding-right: 1em;">Foreign Keys</td>
                    <td>
                        <?php
                        $checked = '';
                        if ($properties[Configuration::DB_FOREIGN_KEYS]) {
                            $checked = ' checked ';
                        }
                        ?>
                        <input type="checkbox" name="<?php echo Configuration::DB_FOREIGN_KEYS;?>"
                            id="db_foreign_keys" value="true"
                            <?php echo $checked;?> style="vertical-align: middle; margin: 0;">                    
                    </td>
                </tr>
            </tbody>
        </table>
        </fieldset>
        
        <fieldset class="config-nested">
        <legend>Database Logging</legend>
        <table>
            <tbody>
          
                <!-- DATABASE LOGGING -->
                <tr>
                    <td>Database logging enabled</td>
                    <td>
                        <?php
                        $checked = '';
                        if ($properties[Configuration::DB_LOGGING]) {
                            $checked = ' checked ';
                        }
                        ?>
                        <input type="checkbox" name="<?php echo Configuration::DB_LOGGING;?>" value="true"
                            <?php echo $checked;?> style="vertical-align: middle; margin: 0;">                    
                    </td>
                    <td>
                        <a href="#" id="database-logging-help-link" class="etl-help" style="margin-left: 2em">?</a> 
                        <div id="database-logging-help" title="Database Logging" style="display: none;">
                            <?php echo Help::getHelpWithPageLink('database-logging', $module); ?>
                        </div>  
                    </td>
                </tr>
          
                <!-- DATABASE LOG TABLE -->
                <tr>
                    <td>Database log table</td>
                    <td><input type="text" name="<?php echo Configuration::DB_LOG_TABLE;?>"
                        value="<?php echo Filter::escapeForHtmlAttribute($properties[Configuration::DB_LOG_TABLE]);?>"/>
                    </td>
                </tr>
                
                <!-- DATABASE EVENT LOG TABLE -->
                <tr>
                    <td style="padding-right: 1em;">Database event log table</td>
                    <?php $dbEventLogTable = $properties[Configuration::DB_EVENT_LOG_TABLE]; ?>
                    <td><input type="text" name="<?php echo Configuration::DB_EVENT_LOG_TABLE;?>"
                        value="<?php echo Filter::escapeForHtmlAttribute($dbEventLogTable);?>"/>
                    </td>
                </tr>
            </tbody>
        </table>
        </fieldset>

        
        <fieldset class="config-nested">
        <legend>E-mail Notifications</legend>
        <table>
            <tbody>
                
                <!-- E-MAIL ERRORS -->      
                <tr>
                    <td>E-mail errors</td>
                    <td>
                        <?php
                        $checked = '';
                        if ($properties[Configuration::EMAIL_ERRORS]) {
                            $checked = ' checked ';
                        }
                        ?>
                        <input type="checkbox" name="<?php echo Configuration::EMAIL_ERRORS;?>" value="true"
                            <?php echo $checked;?> style="vertical-align: middle; margin: 0;">
                    </td>
                    <td>
                        <!-- E-MAIL NOTIFICATION HELP BUTTON -->
                        <a href="#" id="email-notifications-help-link" class="etl-help" style="margin-left: 2em">?</a> 
                        <div id="email-notifications-help" title="E-mail Notifications" style="display: none;">
                            <?php echo Help::getHelpWithPageLink('email-notifications', $module); ?>
                        </div>  
                    </td>
                </tr>

                <!-- E-MAIL SUMMARY -->
                <tr>
                    <td style="padding-right: 1em;">E-mail summary</td>
                    <td>
                        <?php
                        $checked = '';
                        if ($properties[Configuration::EMAIL_SUMMARY]) {
                            $checked = ' checked ';
                        }
                        ?>
                        <input type="checkbox" name="<?php echo Configuration::EMAIL_SUMMARY;?>" value="true"
                            <?php echo $checked;?> style="vertical-align: middle; margin: 0;">
                    </td>
                </tr>
                
                <!-- E-MAIL SUBJECT -->
                <tr>
                    <td>E-mail subject</td>
                    <td><input type="text" name="<?php echo Configuration::EMAIL_SUBJECT;?>" size="64"
                        value="<?php echo Filter::escapeForHtmlAttribute($properties[Configuration::EMAIL_SUBJECT]);?>"
                        />
                    </td>
                </tr>
                
                <!-- E-MAIL TO LIST -->
                <tr>
                    <td>E-mail to list</td>
                    <td><input type="text" name="<?php echo Configuration::EMAIL_TO_LIST;?>" size="64"
                        value="<?php echo Filter::escapeForHtmlAttribute($properties[Configuration::EMAIL_TO_LIST]);?>"
                        />                  
                    </td>
                </tr>
            </tbody>
        </table>
        </fieldset>

        <fieldset class="config-nested">
        <legend>
            <label for="<?php echo Configuration::PRE_PROCESSING_SQL;?>">Pre-Processing SQL</label>
        </legend>        
        <table>
            <tbody>          
                
                <!-- PRE-PROCESSING SQL -->
                <tr>
                    <td style="padding-right: 1em;">SQL</td>
                    <td>
                        <?php
                        $sql = $properties[Configuration::PRE_PROCESSING_SQL];
                        $sqlName = Configuration::PRE_PROCESSING_SQL;
                        ?>
                        <textarea rows="10" cols="70"
                            style="margin-top: 4px; margin-bottom: 4px;"
                            id="<?php echo $sqlName;?>"
                            name="<?php echo $sqlName;?>"><?php echo Filter::escapeForHtml($sql);?></textarea>
                    </td>                   
                    <td>
                        <a href="#" id="pre-processing-sql-help-link" class="etl-help"
                           style="margin-left: 2em;">?</a>                      
                        <div id="pre-processing-sql-help" title="Pre-Processing SQL" style="display: none;">
                            <?php echo Help::getHelpWithPageLink('pre-processing-sql', $module); ?>
                        </div>                         
                    </td>
                </tr>

            </tbody>
        </table>
        </fieldset>
                   
        <fieldset class="config-nested">
        <legend>
            <label for="<?php echo Configuration::POST_PROCESSING_SQL;?>">Post-Processing SQL</label>
        </legend>        
        <table>
            <tbody>          
                
                <!-- POST-PROCESSING SQL -->
                <tr>
                    <td style="padding-right: 1em;">SQL</td>
                    <td>
                        <?php
                        $sql = $properties[Configuration::POST_PROCESSING_SQL];
                        $sqlName = Configuration::POST_PROCESSING_SQL;
                        ?>
                        <textarea rows="10" cols="70"
                            style="margin-top: 4px; margin-bottom: 4px;"
                            id="<?php echo $sqlName;?>"
                            name="<?php echo $sqlName;?>"><?php echo Filter::escapeForHtml($sql);?></textarea>
                    </td>                   
                    <td>
                        <a href="#" id="post-processing-sql-help-link" class="etl-help"
                           style="margin-left: 2em;">?</a>                      
                        <div id="post-processing-sql-help" title="Post-Processing SQL" style="display: none;">
                            <?php echo Help::getHelpWithPageLink('post-processing-sql', $module); ?>
                        </div>                         
                    </td>
                </tr>

            </tbody>
        </table>
        </fieldset>
    </fieldset>


    <fieldset class="config">
    <table style="width: 50%; margin: 0 auto;">
        <tr>
            <td style="text-align: center;">&nbsp;</td>
            <td style="text-align: center;">
                <input style="font-weight: bold;" type="submit" name="submitValue" value="Save"/>
                <input style="font-weight: bold;" type="submit" name="submitValue"
                       value="Save and Exit" style="margin-left: 24px;"/>
                <input style="font-weight: bold;" type="submit" name="submitValue"
                       value="Cancel" style="margin-left: 24px;" />
            </td>
            <td style="text-align: center;">&nbsp;</td>
        </tr>
    </table>
    </fieldset>
    

    <?php Csrf::generateFormToken(); ?>
</form>


    <?php
#----------------------------------------------
# Parse Result (for rules check)
#----------------------------------------------

    $status = $parseResult[0];
    $parseMessages = nl2br($parseResult[1]);

    $class = '';
    if (strcasecmp($status, 'valid') === 0) {
        $class = ' class="darkgreen" ';
    } elseif (strcasecmp($status, 'warn') === 0) {
        $class = ' class="yellow" ';
        $status = 'warning';
    } elseif (strcasecmp($status, 'error') === 0) {
        $class = ' class="red" ';
    }


    echo '<div id ="parse-result" style="display: none;" title="Transformation Rules Check">' . "\n";
    echo '<div ' . $class . '>' . "\n";
    echo '<strong>Status: ' . $status . "</strong>\n";
    echo '</div><br/>' . "\n";
    echo Filter::sanitizeRulesStatus($parseMessages) . "\n";
    echo '</div>' . "\n";

    if (!empty($parseResult)) {
        ?>

<script>
    $('#parse-result').dialog({dialogClass: 'etl-rules-check', width: '500px'})
        dialog('widget').position({my: 'right', at: 'right', of: '#check-rules-button'})
    ;
</script>

        <?php
    }  // End if parse result not empty
    ?>


    <?php
#------------------------------------------------------
# End, if configuration is not empty
#------------------------------------------------------
}
?>


<?php
// See JSON output of properties for REDCap-ETL
#if (isset($configuration)) {
#    $json = $configuration->getRedCapEtlJsonProperties();
#    $json = json_encode(json_decode($json), JSON_PRETTY_PRINT);
#    print "<pre>\n";
#    print_r($json);
#    print "</pre>\n";
#}

?>

