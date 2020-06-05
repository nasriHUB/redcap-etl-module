<?php
#-------------------------------------------------------
# Copyright (C) 2019 The Trustees of Indiana University
# SPDX-License-Identifier: BSD-3-Clause
#-------------------------------------------------------

namespace IU\RedCapEtlModule\WebTests;

use Behat\Behat\Tester\Exception\PendingException;
use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;

use Behat\MinkExtension\Context\MinkContext;
use Behat\Behat\Context\SnippetAcceptingContext;

/**
 * Defines application features from the specific context.
 */
class FeatureContext extends MinkContext implements SnippetAcceptingContext
{
    const CONFIG_FILE = __DIR__.'/../config.ini';

    private $testConfig;
    private $timestamp;
    private $baseUrl;

    private static $featureFileName;

    /**
     * Initializes context.
     *
     * Every scenario gets its own context instance.
     * You can also pass arbitrary arguments to the
     * context constructor through behat.yml.
     */
    public function __construct()
    {
        $this->timestamp = date('Y-m-d-H-i-s');
        $this->testConfig = new TestConfig(self::CONFIG_FILE);
        $this->baseUrl = $this->testConfig->getRedCap()['base_url'];
    }

    /** @BeforeFeature */
    public static function setupFeature($scope)
    {
        $feature = $scope->getFeature();
        $filePath = $feature->getFile();
        $fileName = pathinfo($filePath, PATHINFO_FILENAME);
        self::$featureFileName = $fileName;
    }

    /** @AfterFeature */
    public static function teardownFeature($scope)
    {
    }


    /**
     * @BeforeScenario
     */
    public function setUpBeforeScenario()
    {
        $cookieName  = 'code-coverage-id';
        $cookieValue = 'web-test';
        $this->getSession()->setCookie($cookieName, $cookieValue);
        echo "Cookie '{$cookieName}' set to '{$cookieValue}'\n";

        $this->setMinkParameter('base_url', $this->baseUrl);
        echo "Base URL set to: ".$this->baseUrl;
    }

    /**
     * @AfterScenario
     */
    public function afterScenario($event)
    {
        $session = $this->getSession();
        $session->reset();
        #print_r(get_class_methods($session));

        $scenario = $event->getScenario();
        $tags = $scenario->getTags();

        if ($scenario->hasTag('modified-help-for-batch-size')) {
            Util::logInAsAdminAndAccessRedCapEtl($session);
            $page = $session->getPage();
            $page->clickLink("Help Edit");
            $page->clickLink("Batch Size");
            $page->fillField("customHelp", "");
            $page->selectFieldOption("helpSetting", "Use default text");
            $page->pressButton("Save");
        }
    }


    /**
     * @Given /^I wait$/
     */
    public function iWait()
    {
        $this->getSession()->wait(10000);
    }


    /**
     * @Given /^ETL configuration "([^"]*)" does not exist$/
     */
    public function etlConfigurationDoesNotExist($configName)
    {
        $session = $this->getSession();
        Util::deleteEtlConfigurationIfExists($session, $configName);
    }

    /**
     * @Given /^I am logged in as user$/
     */
    public function iAmLoggedInAsUser()
    {
        $session = $this->getSession();
        Util::loginAsUser($session);
    }

    /**
     * @Then /^Print element "([^"]*)" text$/
     */
    public function printElementText($css)
    {
        $session = $this->getSession();
        $page = $session->getPage();
        $element = $page->find('css', $css);
        $text = $element->getText();
        print "{$text}\n";
    }

    /**
     * @Then /^Print select "([^"]*)" text$/
     */
    public function printSelectText($selectCss)
    {
        $session = $this->getSession();
        $page = $session->getPage();
        $select = $page->find('css', $selectCss);
        if (!empty($select)) {
            #$html = $select->getHtml();
            #print "\n{$html}\n\n";
            $option = $page->find('css', $selectCss." option:selected");
            #$option = $select->find('css', "option:selected");
            #$option = $select->find('xpath', "//option[@selected]");
            if (!empty($option)) {
                $text = $option->getText();
                print "{$text}\n";
            } else {
                print "Selected option not found\n";
            }
        } else {
            print 'Select "'.$selectCss.'" not found'."\n";
        }
    }

    /**
     * @Then /^I should see tabs? ("([^"]*)"(,(\s)*"([^"]*)")*)$/
     */
    public function iShouldSeeTabs($tabs)
    {
        $tabs = explode(',', $tabs);
        for ($i = 0; $i < count($tabs); $i++) {
            # trim standard character plus quotes
            $tabs[$i] = trim($tabs[$i], " \t\n\r\0\x0B\"");
        }
        #print_r($tabs);

        $session = $this->getSession();
        Util::checkTabs($session, $tabs);
    }

    /**
     * @Then /^I should not see tabs? ("([^"]*)"(,(\s)*"([^"]*)")*)$/
     */
    public function iShouldNotSeeTabs($tabs)
    {
        $tabs = explode(',', $tabs);
        for ($i = 0; $i < count($tabs); $i++) {
            # trim standard character plus quotes
            $tabs[$i] = trim($tabs[$i], " \t\n\r\0\x0B\"");
        }
        #print_r($tabs);

        $session = $this->getSession();
        $shouldFind = false;
        Util::checkTabs($session, $tabs, $shouldFind);
    }


    /**
     * @Then /^I should see table headers ("([^"]*)"(,(\s)*"([^"]*)")*)$/
     */
    public function iShouldSeeTableHeaders($headers)
    {
        $headers = explode(',', $headers);
        for ($i = 0; $i < count($headers); $i++) {
            # trim standard character plus quotes
            $headers[$i] = trim($headers[$i], " \t\n\r\0\x0B\"");
        }

        $session = $this->getSession();
        
        Util::checkTableHeaders($session, $headers);
    }



    /**
     * @When /^I print window names$/
     */
    public function iPrintWindowNames()
    {
        $windowName = $this->getSession()->getWindowName();
        $windowNames = $this->getSession()->getWindowNames();
        print "Current window: {$windowName} [".array_search($windowName, $windowNames)."]\n";
        print_r($windowNames);
    }

    /**
     * @When /^print link "([^"]*)"$/
     */
    public function printLink($linkId)
    {
        $session = $this->getSession();

        $page = $session->getPage();
        $link = $page->findLink($linkId);
        print "\n{$linkId}\n";
        print_r($link);
    }

    /**
     * @When /^I click on element containing "([^"]*)"$/
     */
    public function iClickOnElementContaining($text)
    {
        $session = $this->getSession();

        $page = $session->getPage();
        $element = $page->find('xpath', "//*[contains(text(), '{$text}')]");
        $element->click();
    }

    /**
     * @When /^I search for user$/
     */
    public function iSearchForUser()
    {
        $user = $this->testConfig->getUser();

        $session = $this->getSession();
        $page = $session->getPage();

        $page->fillField('user-search', $user['username']);

        sleep(4);

        $element = $page->find('xpath', "//*[contains(text(), '".$user['email']."')]");
        $element->click();
    }


    /**
     * @When /^I go to new window in (\d+) seconds$/
     */
    public function iGoToNewWindow($seconds)
    {
        sleep($seconds);  // Need time for new window to open
        $windowNames = $this->getSession()->getWindowNames();
        $numWindows  = count($windowNames);

        $currentWindowName  = $this->getSession()->getWindowName();
        $currentWindowIndex = array_search($currentWindowName, $windowNames);

        if (isset($currentWindowIndex) && $numWindows > $currentWindowIndex + 1) {
            $this->getSession()->switchToWindow($windowNames[$currentWindowIndex + 1]);
            #$this->getSession()->reset();
        }
    }

    /**
     * @When /^I wait for (\d+) seconds$/
     */
    public function iWaitForSeconds($seconds)
    {
        sleep($seconds);
    }

    /**
     * @When /^I go to old window$/
     */
    public function iGoToOldWindow()
    {
        $windowNames = $this->getSession()->getWindowNames();

        $currentWindowName  = $this->getSession()->getWindowName();
        $currentWindowIndex = array_search($currentWindowName, $windowNames);

        if (isset($currentWindowIndex) && $currentWindowIndex > 0) {
            $this->getSession()->switchToWindow($windowNames[$currentWindowIndex - 1]);
            $this->getSession()->restart();
        }
    }

    /**
     * @When /^I log in as user$/
     */
    public function iLogInAsUser()
    {
        $session = $this->getSession();
        Util::loginAsUser($session);
    }

    /**
     * @When /^I log in as admin$/
     */
    public function iLogInAsAdmin()
    {
        $session = $this->getSession();
        Util::loginAsAdmin($session);
    }


    /**
     * @When /^I log out$/
     */
    public function iLogOut()
    {
        $session = $this->getSession();
        Util::logOut($session);
    }

    /**
     * @When /^I access the admin interface$/
     */
    public function iAccessTheAdminInterface()
    {
        $session = $this->getSession();
        Util::logInAsAdminAndAccessRedCapEtl($session);
    }

    /**
     * @When /^I log in as admin and access REDCap-ETL$/
     */
    public function i()
    {
        $session = $this->getSession();
        Util::logInAsAdminAndAccessRedCapEtl($session);
    }

    /**
     * @When /^I log in as user and access REDCap-ETL for test project$/
     */
    public function iLogInAsUserAndAccessRedCapEtlForTestProject()
    {
        $session = $this->getSession();
        Util::logInAsUserAndAccessRedCapEtlForTestProject($session);
    }

    /**
     * @When /^I select the test project$/
     */
    public function iSelectTheTestProject()
    {
        $session = $this->getSession();
        Util::selectTestProject($session);
    }

    /**
     * @When /^I select user from "([^"]*)"$/
     */
    public function iSelectUserFromSelect($select)
    {
        $session = $this->getSession();
        Util::selectUserFromSelect($session, $select);
    }

    /**
     * @When /^I follow configuration "([^"]*)"$/
     */
    public function iFollowConfiguration($configName)
    {
        $session = $this->getSession();
        EtlConfigsPage::followConfiguration($session, $configName);
    }

    /**
     * @When /^I configure configuration "([^"]*)"$/
     */
    public function iConfigureConfiguration($configName)
    {
        $session = $this->getSession();
        EtlConfigsPage::configureConfiguration($session, $configName);
    }

    /**
     * @When /^I copy configuration "([^"]*)" to "([^"]*)"$/
     */
    public function iCopyConfiguration($configName, $copyToConfigName)
    {
        $session = $this->getSession();
        EtlConfigsPage::copyConfiguration($session, $configName, $copyToConfigName);
    }

    /**
     * @When /^I rename configuration "([^"]*)" to "([^"]*)"$/
     */
    public function iRenameConfiguration($configName, $newConfigName)
    {
        $session = $this->getSession();
        EtlConfigsPage::renameConfiguration($session, $configName, $newConfigName);
    }

    /**
     * @When /^I delete configuration "([^"]*)"$/
     */
    public function iDeleteConfiguration($configName)
    {
        $session = $this->getSession();
        EtlConfigsPage::deleteConfiguration($session, $configName);
    }

    /**
     * @When /^I delete configuration "([^"]*)" if it exists$/
     */
    public function iDeleteConfigurationIfExists($configName)
    {
        $session = $this->getSession();
        EtlConfigsPage::deleteConfigurationIfExists($session, $configName);
    }


    /**
     * @When /^I follow server "([^"]*)"$/
     */
    public function iFollowServer($serverName)
    {
        $session = $this->getSession();
        EtlServersPage::followServer($session, $serverName);
    }

    /**
     * @When /^I configure server "([^"]*)"$/
     */
    public function iConfigureServer($serverName)
    {
        $session = $this->getSession();
        EtlServersPage::configureServer($session, $serverName);
    }

    /**
     * @When /^I copy server "([^"]*)" to "([^"]*)"$/
     */
    public function iCopyServer($serverName, $copyToServerName)
    {
        $session = $this->getSession();
        EtlServersPage::copyServer($session, $serverName, $copyToServerName);
    }

    /**
     * @When /^I rename server "([^"]*)" to "([^"]*)"$/
     */
    public function iRenameServer($serverName, $newServerName)
    {
        $session = $this->getSession();
        EtlServersPage::renameServer($session, $serverName, $newServerName);
    }

    /**
     * @When /^I delete server "([^"]*)"$/
     */
    public function iDeleteServer($serverName)
    {
        $session = $this->getSession();
        EtlServersPage::deleteServer($session, $serverName);
    }

    /**
     * @When /^I schedule for next hour$/
     */
    public function iScheduleForNextHour()
    {
        $session = $this->getSession();
        SchedulePage::scheduleForNextHour($session);
    }

    /**
     * @When /^I check test project access$/
     */
    public function iCheckTestProjectAccess()
    {
        $testConfig = new TestConfig(FeatureContext::CONFIG_FILE);
        $userConfig = $testConfig->getUser();
        $testProjectTitle = $userConfig['test_project_title'];

        $session = $this->getSession();
        $page = $session->getPage();

        $element = $page->find("xpath", "//tr[contains(td[3],'".$testProjectTitle."')]/td[1]/input[@type='checkbox']");

        $element->click();
    }

    /**
     * @When /^I check mailinator for "([^"]*)"$/
     */
    public function iCheckMailinatorFor($emailPrefix)
    {
        $session = $this->getSession();
        Util::mailinator($session, $emailPrefix);
    }

    /**
     * @When /^I run the cron process$/
     */
    public function iRunTheCronProcess()
    {

        # WORK IN PROGRESS
        # Need to do 2 things: reset the last cron runtime, so the process will run
        # Access the cron script (can access through http)
        $session = $this->getSession();
        Util::mailinator($session, $emailPrefix);
    }

    /**
     * @Then I should see :textA followed by :textB
     */
    public function iShouldSeeFollowedBy($textA, $textB)
    {
        $session = $this->getSession();
        Util::findTextFollowedByText($session, $textA, $textB);
    }

    /**
     * @When /^I click on the user$/
     */
    public function iClickOnTheUser()
    {
        $user = $this->testConfig->getUser();
        $username = $user['username'];

        $session = $this->getSession();
        $page = $session->getPage();

        $page->clickLink($username);
    }

    /**
     * @When /^I check the box to remove the user$/
     */
    public function iCheckTheBoxToRemoveTheUser()
    {
        $user = $this->testConfig->getUser();
        $username = $user['username'];

        $session = $this->getSession();
        $page = $session->getPage();

        $checkboxName = 'removeUserCheckbox['.$username.']';

        $page->checkField($checkboxName);
    }

    /**
     * @When I choose :textA as the access level
     */
    public function iChooseAsTheAccessLevel($textA)
    {
        $session = $this->getSession();
        Util::chooseAccessLevel($session, $textA);
    }


    /**
     * @Then I :textA see a/an :textB item for the user
     */
    public function iSeeAnItemForTheUser($textA, $textB)
    {
        $user = $this->testConfig->getUser();
        $username = $user['username'];

        $session = $this->getSession();
        Util::findSomethingForTheUser($session, $username, $textA, $textB);
    }


    /**
     * @When I confirm the popup [nal WIP: was in the process of trying to get this to work]
     */
    #public function iConfirmThePopup()
    #{
    #    $this->getSession()->getDriver()->getWebDriverSession()->accept_alert();
    #}
}
