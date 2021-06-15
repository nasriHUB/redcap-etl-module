<?php
#-------------------------------------------------------
# Copyright (C) 2019 The Trustees of Indiana University
# SPDX-License-Identifier: BSD-3-Clause
#-------------------------------------------------------

/** @var \IU\RedCapEtlModule\RedCapEtlModule $module */

require_once __DIR__ . '/../dependencies/autoload.php';

use IU\RedCapEtlModule\Csrf;
use IU\RedCapEtlModule\Filter;
use IU\RedCapEtlModule\RedCapDb;

$error   = '';
$warning = '';
$success = '';

$pid = PROJECT_ID;
$username = USERID;

$servers = array();

try {
    #-----------------------------------------------------------
    # Check that the user has permission to access this page
    #-----------------------------------------------------------
    $configCheck = true;
    $configuration = $module->checkUserPagePermission(USERID, $configCheck);

    #-------------------------------------------------------------------
    # Check for test mode (which should only be used for development)
    #-------------------------------------------------------------------
    $testMode = false;
    if (@file_exists(__DIR__ . '/../test-config.ini')) {
        $testMode = true;
    }

    $selfUrl  = $module->getUrl("web/run.php");

    $servers  = $module->getUserAllowedServersBasedOnAccessLevel(USERID);

    #------------------------------------------
    # Get the configuration type
    #------------------------------------------
    $configType = Filter::sanitizeLabel($_POST['configType']);
    if (empty($configType)) {
        $configType = Filter::sanitizeLabel($_GET['configType']);
        if (empty($configType)) {
            $configType = $_SESSION['configType'];
        }
    } else {
        $_SESSION['configType'] = $configType;
    }

    #------------------------------------------
    # Get the (task) configuration name
    #------------------------------------------
    $configName = Filter::sanitizeLabel($_POST['configName']);
    if (empty($configName)) {
        $configName = $_SESSION['configName'];
    } else {
        $_SESSION['configName'] = $configName;
    }

    #------------------------------------------
    # Get the workflow (configuration) name
    #------------------------------------------
    $workflowName = Filter::sanitizeLabel($_POST['workflowName']);
    if (empty($workflowName)) {
        $workflowName = $_SESSION['workflowName'];
    } else {
        $_SESSION['workflowName'] = $workflowName;
    }

    #------------------------------------------
    # Get the server
    #------------------------------------------
    $server = Filter::stripTags($_POST['server']);
    if (empty($server)) {
        $server = $_SESSION['server'];
    } else {
        $_SESSION['server'] = $server;
    }

    #-------------------------
    # Set the submit value
    #-------------------------
    $submit = '';
    if (array_key_exists('submit', $_POST)) {
        $submit = Filter::sanitizeButtonLabel($_POST['submit']);
    }

    #-----------------------------------------
    # Process a submit
    #-----------------------------------------
    $runOutput = '';
    if (strcasecmp($submit, 'Run') === 0) {
        if ($configType === 'task') {
            if (empty($configName)) {
                $error = 'ERROR: No ETL configuration specified.';
            } elseif (!isset($configuration)) {
                $error = 'ERROR: No ETL configuration found for ' . $configName . '.';
            } else {
                $configuration->validateForRunning();
                $isCronJob = false;
                $runOutput = $module->run($configName, $server, $isCronJob);
            }
        } elseif ($configType === 'workflow') {
            if (empty($workflowName)) {
                $error = 'ERROR: No workflow specified.';
            } else {
                $isCronJob = false;
                $originatingProjectId = $pid;

                #Get projects that this user has access to
                $db = new RedCapDb();
                $userProjects = $db->getUserProjects($username);
                $runOutput = $module->runWorkflow(
                    $workflowName,
                    $server,
                    $username,
                    $userProjects,
                    $isCronJob,
                    $originatingProjectId
                );
            }
        }
    }
} catch (\Exception $exception) {
    $error = 'ERROR: ' . $exception->getMessage();
}
?>


<?php
#--------------------------------------------
# Include REDCap's project page header
#--------------------------------------------
ob_start();
require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
$buffer = ob_get_clean();
$cssFile = $module->getUrl('resources/redcap-etl.css');
$link = '<link href="' . $cssFile . '" rel="stylesheet" type="text/css" media="all">';
$buffer = str_replace('</head>', "    " . $link . "\n</head>", $buffer);
echo $buffer;
?>

<div class="projhdr"> 
    <img style="margin-right: 7px;" src="<?php echo APP_PATH_IMAGES ?>database_table.png" alt="">REDCap-ETL
    <?php
    if ($testMode) {
        echo '<span style="color: blue;">[TEST MODE]</span>';
    }
    ?>
</div>


<?php
$module->renderProjectPageContentHeader($selfUrl, $error, $warning, $success);
?>


<?php
#-------------------------------------
# Configuration selection form
#-------------------------------------
?>
<form action="<?php echo $selfUrl;?>" method="post" 
    style="padding: 14px; margin-bottom: 0px; border: 1px solid #ccc;">

    <div style="text-align: right">
    Help
    </div>

    <!-- CONFIGURATION INFORMATION -->
    <table style="margin-bottom: 12px;">
        <!-- TASKS -->
        <tr>
            <td>
                <?php
                $checked = '';
                if ($configType === 'task') {
                    $checked = 'checked';
                }
                ?>
                <input type="radio" name="configType" value="task" id="task" <?php echo $checked ?>/>
                <label for="task">ETL Task</label>
            </td>
            <td>
                <select name="configName" onchange="this.form.submit()">
                <?php
                $configNames = $module->getAccessibleConfigurationNames();
                array_unshift($configNames, '');
                foreach ($configNames as $value) {
                    if (strcmp($value, $configName) === 0) {
                        echo '<option value="' . Filter::escapeForHtmlAttribute($value) . '" selected>'
                            . Filter::escapeForHtml($value) . "</option>\n";
                    } else {
                        echo '<option value="' . Filter::escapeForHtmlAttribute($value) . '">'
                            . Filter::escapeForHtml($value) . "</option>\n";
                    }
                }
                ?>
                </select>
            </td>
        </tr>

        <tr>
            <td>
                <?php
                $checked = '';
                if ($configType === 'workflow') {
                    $checked = 'checked';
                }
                ?>
                <input type="radio" name="configType" value="workflow" id="workflow" <?php echo $checked ?>/>
                <label for="workflow">ETL Workflow</label>
                &nbsp;
            </td>
            <td>
                <?php
                $excludeIncomplete = true;
                $projectWorkflows = $module->getProjectAvailableWorkflows($pid, $excludeIncomplete);
                ?>
                <select name="workflowName" onchange="this.form.submit()">
                <?php
                foreach ($projectWorkflows as $value) {
                    if (strcmp($value, $workflowName) === 0) {
                        echo '<option value="' . Filter::escapeForHtmlAttribute($value) . '" selected>'
                            . Filter::escapeForHtml($value) . "</option>\n";
                    } else {
                        echo '<option value="' . Filter::escapeForHtmlAttribute($value) . '">'
                            . Filter::escapeForHtml($value) . "</option>\n";
                    }
                }
                ?>
                </select>
            </td>
        </tr>
    </table>

    <!-- SERVER SELECTION -->
    <div style="margin-bottom: 22px;">
        <span style="margin-right: 4px;">ETL Server:</span>
        <?php
        echo '<select name="server" id="serverId">' . "\n";
        echo '<option value=""></option>' . "\n";
        foreach ($servers as $serverName) {
            $serverConfig = $module->getServerConfig($serverName);
            if (isset($serverConfig) && $serverConfig->getIsActive()) {
                $selected = '';
                if ($serverName === $server) {
                    $selected = 'selected';
                }
                echo '<option value="' . Filter::escapeForHtmlAttribute($serverName) . '" ' . $selected . '>'
                    . Filter::escapeForHtml($serverName) . "</option>\n";
            }
        }
        echo "</select>\n";
        ?>
    </div>

    <!-- RUN BUTTON -->
    <div>
        <input type="submit" name="submit" value="Run"
                style="color: #008000; font-weight: bold; padding: 0px 24px;"
                onclick='$("#runOutput").text(""); $("body").css("cursor", "progress");'/>
    </div>

    <?php Csrf::generateFormToken(); ?>
</form>

<p><pre id="runOutput"><?php echo Filter::escapeForHtml($runOutput);?></pre></p>


<?php require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php'; ?>

