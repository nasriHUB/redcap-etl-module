<?php

/** @var \IU\RedCapEtlModule\RedCapEtlModule $module */

if (!SUPER_USER) {
    exit("Only super users can access this page!");
}

require_once __DIR__.'/../../dependencies/autoload.php';

use IU\RedCapEtlModule\AdminConfig;
use IU\RedCapEtlModule\RedCapEtlModule;
use IU\RedCapEtlModule\ServerConfig;

$selfUrl         = $module->getUrl(RedCapEtlModule::CRON_DETAIL_PAGE);
$serverConfigUrl = $module->getUrl(RedCapEtlModule::SERVER_CONFIG_PAGE);
$userUrl         = $module->getURL(RedCapEtlModule::USER_CONFIG_PAGE);

$adminConfig = $module->getAdminConfig();
    

$selectedDay = $_POST['selectedDay'];
if (!isset($selectedDay)) {
    $selectedDay = $_GET['selectedDay'];
    if (!isset($selectedDay)) {
        $selectedDay = 0;
    }
}

$selectedTime = $_POST['selectedTime'];
if (!isset($selectedTime)) {
    $selectedTime = $_GET['selectedTime'];
    if (!isset($selectedTime)) {
        $selectedTime = 0;
    }
}


$cronJobs = $module->getCronJobs($selectedDay, $selectedTime);


$submitValue = $_POST['submitValue'];
if (strcasecmp($submitValue, 'Save') === 0) {
    $times = $_POST['times'];
    $adminConfig->setAllowedCronTimes($times);
    
    $allowOnDemand = $_POST['allowOnDemand'];
    $adminConfig->setAllowOnDemand($allowOnDemand);
    
    $allowCron = $_POST['allowCron'];
    $adminConfig->setAllowCron($allowCron);
    
    $module->setAdminConfig($adminConfig);
    $success = "Admin configuration saved.";
}

?>

<?php #include APP_PATH_DOCROOT . 'ControlCenter/header.php'; ?>

<?php
#--------------------------------------------
# Include REDCap's project page header
#--------------------------------------------
ob_start();
include APP_PATH_DOCROOT . 'ControlCenter/header.php';
$buffer = ob_get_clean();
$cssFile = $module->getUrl('resources/redcap-etl.css');
$link = '<link href="'.$cssFile.'" rel="stylesheet" type="text/css" media="all">';
$buffer = str_replace('</head>', "    ".$link."\n</head>", $buffer);
echo $buffer;
?>

<h4><img style="margin-right: 7px;" src="<?php echo APP_PATH_IMAGES ?>table_gear.png">REDCap-ETL Admin</h4>


<?php

$module->renderAdminTabs($selfUrl);

#----------------------------
# Display messages, if any
#----------------------------
$module->renderErrorMessageDiv($error);
$module->renderSuccessMessageDiv($success);

?>

<?php
#print "<pre>POST:\n"; print_r($_POST); print "</pre>\n";
?>

<?php
#---------------------------------
# Server selection form
#---------------------------------
$days = AdminConfig::DAY_LABELS;
$times = $adminConfig->getTimeLabels();
?>

<form action="<?php echo $selfUrl;?>" method="post"
      style="padding: 4px; margin-bottom: 12px; border: 1px solid #ccc; background-color: #ccc;">
    <span style="font-weight: bold;">Day:</span>
    <select name="selectedDay" onchange="this.form.submit()">
    <?php
    foreach ($days as $value => $label) {
        if (strcmp($value, $selectedDay) === 0) {
            echo '<option value="'.$value.'" selected>'.$label."</option>\n";
        } else {
            echo '<option value="'.$value.'">'.$label."</option>\n";
        }
    }
    ?>
    </select>
    
    <span style="font-weight: bold; margin-left: 1em;">Time:</span>
    <select name="selectedTime" onchange="this.form.submit()">
    <?php
    foreach ($times as $value => $label) {
        if (strcmp($value, $selectedTime) === 0) {
            echo '<option value="'.$value.'" selected>'.$label."</option>\n";
        } else {
            echo '<option value="'.$value.'">'.$label."</option>\n";
        }
    }
    ?>
    </select>
</form>

<table class="dataTable">
    <thead>
        <tr> <th>User</th> <th>Project ID</th> <th>Configuration</th> <th>Server</th> </tr>
    </thead>
    <tbody>
        <?php
        $row = 1;
        foreach ($cronJobs as $cronJob) {
            $server = $cronJob['server'];
            $serverUrl = $serverConfigUrl.'&serverName='.$server;
            $username  = $cronJob['username'];
            $projectId = $cronJob['projectId'];
            $config    = $cronJob['config'];
            $userConfigUrl = $userUrl.'&username='.$username;
            
            $configUrl = $module->getURL(
                'admin_etl_config.php'.'?config='.$config
                .'&username='.$username.'&pid='.$projectId
            );

            if ($row % 2 === 0) {
                print '<tr class="even">'."\n";
            } else {
                print '<tr class="odd">'."\n";
            }
            print "<td>".'<a href="'.$userConfigUrl.'">'.$cronJob['username'].'</a>'."</td>\n";
            print "<td>".'<a href="'.APP_PATH_WEBROOT.'index.php?pid='.$projectId.'" target="_blank">'
                .$projectId.'</a>'."</td>\n";
            #print "<td>".'<a href="#" class="copyConfig">'.$cronJob['config'].'</a>'."</td>\n";
            print "<td>".'<a href="'.$configUrl.'">'.$config.'</a>'."</td>\n";
            
            if (strcasecmp($server, ServerConfig::EMBEDDED_SERVER_NAME) == 0) {
                print "<td>".$server."</td>\n";
            } else {
                print "<td>".'<a href="'.$serverUrl.'">'.$server.'</a>'."</td>\n";
            }
            
            print "</tr>\n";
            $row++;
        }
        ?>
    </tbody>
</table>

<div id="popup" style="display: none;"></div>

<script>
$(function() {
$('#popup').dialog({
    autoOpen: false,
    open: function(event, ui) {
        $('#popup').load(
            "<?php echo $module->getURL(
                "config_dialog.php?config={$config}&username={$username}"
                ."&projectId={$projectId}"
            ) ?>",
            function() {}
        );
    },
  modal: true,
  minHeight: 600,
  minWidth: 800,
  buttons: {
    'Save Changes': function(){
        $(this).dialog('close');
    },
    'Discard & Exit' : function(){
      $(this).dialog('close');
    }
  }
});
    $(".copyConfig").click(function(){
        var id = this.id;
        var configName = id.substring(4);
        $("#configToCopy").text('"'+configName+'"');
        $('#copyFromConfigName').val(configName);
        $("#popup").dialog("open");
    });
});
</script>

<?php
#print "<pre>\n"; print_r($cronJobs); print "</pre>";
?>

<?php include APP_PATH_DOCROOT . 'ControlCenter/footer.php'; ?>