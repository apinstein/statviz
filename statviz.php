#!/usr/local/php/bin/php -d output_buffering=0
<?php

/******************************************************************************************************************************************************
*   StatViz is log processing system to help you visualize your web server traffic.
*
*   The program reads logfiles and creates two types of graphs:
*   1) A referrer-hit "pairs" graph showing the most frequent "links" to and through your site. It's a great way to understand how visitors
*      most commonly move to and through your site.
*   2) A session clickstream graph. A separate graph is created for each session, giving you a sampling of actual site visitors' tracks on your site.
*
*   Config: See the class statviz_config to see what all of the configurable parameters are.
*
* @todo
*   - Add ability to 'dump state' in continuous mode so you can stop & start the script without losing the state that has built up.
*   - Write a separate application to monitor the output of this script and update the display in real-time.
*
* @author Alan Pinstein <apinstein@mac.com>
*         SourceForge.net project site: https://sourceforge.net/projects/statviz/
*
******************************************************************************************************************************************************/

// constants
define('STATVIZ_CONF_TYPE', 'inifile');

// includes - use include instead of require to allow for graceful checking of failure to include
include_once 'Console/Getopt.php';
include_once 'Config.php';

// check dependencies
if (!class_exists('Console_Getopt')) die('FATAL: Console_GetOpt module required. Please install the PEAR module: Console_GetOpt' . "\n");
if (!class_exists('Config')) die('FATAL: Config module required. Please install the PEAR module: Config' . "\n");

$shortopts = "hcn:d:";
$longopts = array('help', 'config=', 'logfile=', 'logtype=', 'vhostcol=', 'sessioncol=', 'skipbot=', 'skipext=', 'skipurl=', 'hostname=', 'writeconf=', 'outdir=', 'referrermode=', 'continuous==');

$cgo = new Console_Getopt;
$args = $cgo->readPHPArgv();
if (PEAR::isError($args)) { 
    fwrite(STDERR,$args->getMessage()."\n"); 
    exit(1);
} 
$options = $cgo->getopt($args, $shortopts, $longopts);
// Check the options are valid 
if (PEAR::isError($options)) { 
    fwrite(STDERR,$options->getMessage()."\n"); 
    exit(2); 
} 

// handle options
if (count($options[0]) == 1 and ($options[0][0][0] == 'h' or $options[0][0][0] == '--help')) {
    print "Stats Visualizer. Parses http log files and produces DOT files displaying interesting statistics.
    Usage:
        -h, --help              Display this help.
        -c, --config            The path to the config file to use. Any options FOLLOWING this option will override the values from the config file.
        -l, --logile            The path to the logfile to process.
        -t, --logtype           The log format: combined is the only current choice.
        -v, --vhostcol          The column # of the log line containing the Virtual Host Name.
        -s, --sessioncol        The column # of the log line containing the unique session ID.
        -b, --skipbot           Substrings to search the User Agent for to skip bots. Supply as many as you like: -b crawler1 -b crawler2
        -e, --skipext           Extentsions of pages to skip. Supply as many as you like: -e doc -e xls
        -u, --skipurl           URL substrings to skip. Supply as many as you like: -u getcss.php -u test.php
        -n, --hostname          The hostname of the web site. The first one supplied is assumed to be the 'default' hostname; additional ones are aliases.
        --writeconf             Write the configuration used for the run to the specified path (.conf will be added if not supplied)
        -d, --outdir            Output dir for the DOT files. Will attempt to create if not there.
        --referrermode          Which referrers to evaluate ('all' for internal and external, or 'internal' for just internal links)
        --continuous            Continually update reports, generated new reports every nth hit processed (default 10).
        ";
    exit;
}

// set up statviz based on entered options.
$conf = new statviz_config;
$writeConfPath = NULL;
foreach ($options[0] as $optinfo) {
    $optname = $optinfo[0];
    $optvalue = $optinfo[1];
    switch ($optname) {
        case 'c':
        case '--config':
            $conf->loadConfFile($optvalue);
            break;

        case '--logfile':
            $conf->LogFilePath = $optvalue;
            break;

        case '--logtype':
            if ($optvalue == 'combined') {
                // standard setup for combined format
                $conf->LogURLColumn = 5;
                $conf->LogRefColumn = 8;
                $conf->LogDTSColumn = 3;
                $conf->LogUAColumn = 9;
                $conf->LogStatusColumn = 6;
            } else {
                die("Warning: unknown log file type: $optvalue\n");
            }
            break;

        case '--vhostcol':
            $conf->LogVHostColumn = $optvalue - 1;    // assume columns given in 1-based; convert to 0-based
            break;

        case '--sessioncol':
            $conf->LogSessIDColumn = $optvalue - 1;   // assume columns given in 1-based; convert to 0-based
            break;

        case '--skipbot':
            array_push($conf->SkipBotUATokens, $optvalue);
            break;

        case '--skipext':
            array_push($conf->SkipExts, $optvalue);
            break;

        case '--skipurl':
            array_push($conf->SkipURLs, $optvalue);
            break;

        case 'n':
        case '--hostname':
            if ($conf->Domain == '') {
                $conf->Domain = $optvalue;
            } else {
                array_push($conf->HostAliases, $optvalue);
            }
            break;

        case '--writeconf':
            $writeConfPath = $optvalue;
            break;

        case 'd':
        case '--outdir':
            $conf->OutputDir = $optvalue;
            break;

        case '--referrermode':
            $conf->ReferrerFilterMode = $optvalue;
            break;

        case '--continuous':
            $conf->ContinuousMode = ( $optvalue ? $optvalue : 10 );
            break;
    }
}

// post-handle conf
if ($writeConfPath) {
    $conf->saveConfFile($writeConfPath);
}

// start processing logs
$sv = new statviz($conf);
$err = $sv->run();
exit($err);

// **************** FUNCTIONS AND CLASSES ONLY BELOW **************//

class statviz_config
{
    // Log format: indexes are 0-based
    var $LogFilePath;       // logfile abs or rel path to analyze
    var $LogSessIDColumn;   // which column has the uniq sessionID
    var $LogURLColumn;      // which column has the URL
    var $LogRefColumn;      // which column has the referring link
    var $LogDTSColumn;      // which column has the timestamp; ok to be blank (no time reporting)
    var $LogUAColumn;       // which column has the User Agent; ok to be blank (no bot cleaning)
    var $LogVHostColumn;    // which column has the virtual host column; ok to be blank (no detection of external referrers)
    var $LogStatusColumn;   // which column has the HTTP response code
    
    // cleaning options
    var $AcceptHTTPCodes;   // array of http response codes that should be processed
    var $SkipBotUATokens;       // Array of UA tokens for bots to ignore
    var $SkipExts;          // Array of extensions to skip in logfile
    var $SkipURLs;          // array of URLs (regex patterns) to skip
    var $SkipNonDeclaredHosts;  // Should we skip non-declared host names? (see Domain and HostAliases)
    var $IndexPages;        // list of index URLs that will be collapsed to '/req/'.
    var $Domain;            // name of domain being analyzed
    var $HostAliases;       // array of domains for this site; used to determine if a referrer is external or not; automatically includes Domain from config
    var $URLAliasFile;      // file containing a list of aliases to use for certain URLs so they look nicer on graphs. Format: URL<tab>ALIAS, one entry per line.
    var $ReferrerFilterMode;// 'internal' or 'all'. Which referrer -> page links to report on (for Pair reporting)

    // reporting options
    var $GraphNReferrerPairs;   // how many referrer pairs to graph; 0 to disable
    var $GraphNSessions;        // how many of the top individual sessions to graph; 0 to disable
    var $OutputDir;             // where to place the results... will create the dir if it doesn't exist.
    var $ContinuousMode;        // update reports every N clicks.
    var $SessionRequireURLs;    // array of URLs, ANY of which MUST be present in the session for the session to be included in the report.

    function statviz_config() {
        $this->LogFilePath = NULL;

        // default to "combined" format
        $this->LogURLColumn = 5;
        $this->LogRefColumn = 8;
        $this->LogDTSColumn = 3;
        $this->LogUAColumn = 9;
        $this->LogStatusColumn = 6;
        $this->LogSessIDColumn = 0;     // use IP addr by default. Not perfect, but better than nothing.
        // non-combined log type columns
        $this->LogVHostColumn = NULL;

        $this->AcceptHTTPCodes = array(200, 304);
        $this->SkipNonDeclaredHosts = false;
        $this->SkipBotUATokens = array('bot', 'crawler', 'walker', 'slurp');
        $this->SkipExts = array('jpeg', 'jpg', 'gif', 'swf', 'css', 'js', 'ico', 'png');
        $this->SkipURLs = array();
        $this->IndexPages = array('index.htm', 'index.html');
        $this->Domain = '';
        $this->HostAliases = array();
        $this->URLAliasFile = NULL;
        $this->ReferrerFilterMode = 'all';
        $this->GraphNReferrerPairs = 15;
        $this->GraphNSessions = 15;
        $this->SessionRequireURLs = array();
        $this->OutputDir = NULL;
        $this->ContinuousMode = 0;
    }

    function loadConfFile($path) {
        // load config file and replace inst vars...
        $c = new Config();
        $root =& $c->parseConfig($path, STATVIZ_CONF_TYPE);
        if (PEAR::isError($root)) {
            die("Error loading config file $path: " . $root->getMessage() . "\n" . $root->getUserInfo() . "\n");
        }

        // go through each section
        for ($secI = 0; $secI < $root->countChildren(); $secI++) {
            $section = $root->getChild($secI);
            // go through each directive
            for ($dirI = 0; $dirI < $section->getChild($dirI); $dirI++) {
                $dir = $section->getChild($dirI);
                $name = $dir->getName();
                switch ($name) {
                    case 'AcceptHTTPCodes':
                    case 'SkipBotUATokens':
                    case 'SkipExts':
                    case 'SkipURLs':
                    case 'IndexPages':
                    case 'HostAliases':
                    case 'SessionRequireURLs':
                        // array types
                        // de-duplicate
                        if (!in_array($dir->getContent(), $this->$name)) {
                            array_push($this->$name, $dir->getContent());
                        }
                        break;
                    default:
                        // string types
                        $this->$name = $dir->getContent();
                        break;
                }
            }
        }
    }

    function saveConfFile($outPath) {
        // make sure .conf extension
        if (!preg_match('/\.conf$/', $outPath)) $outPath .= '.conf';

        // set up config shell
        $c = new Config();
        $root =& $c->getRoot();

        // add all values as sections
        $logSec =& $root->createSection('LogFile');
        $logSec->createDirective('LogFilePath', $this->LogFilePath);
        $logSec->createDirective('LogSessIDColumn', $this->LogSessIDColumn);
        $logSec->createDirective('LogURLColumn', $this->LogURLColumn);
        $logSec->createDirective('LogRefColumn', $this->LogRefColumn);
		$logSec->createDirective('LogDTSColumn', $this->LogDTSColumn);     
		$logSec->createDirective('LogUAColumn', $this->LogUAColumn);      
		$logSec->createDirective('LogVHostColumn', $this->LogVHostColumn);   
		$logSec->createDirective('LogStatusColumn', $this->LogStatusColumn);  

        foreach ($this->AcceptHTTPCodes as $code) {
            $cleanSec->createDirective('AcceptHTTPCodes', $code);
        }
		foreach ($this->SkipBotUATokens as $code) {
			$cleanSec->createDirective('SkipBotUATokens', $code);
		}
		foreach ($this->SkipExts as $code) {
			$cleanSec->createDirective('SkipExts', $code);
		}
		foreach ($this->SkipURLs as $code) {
			$cleanSec->createDirective('SkipURLs', $code);
		}
		$cleanSec->createDirective('SkipNonDeclaredHosts', $this->SkipNonDeclaredHosts);
		foreach ($this->IndexPages as $code) {
			$cleanSec->createDirective('IndexPages', $code);
		}
		$cleanSec->createDirective('Domain', $this->Domain);
		foreach ($this->HostAliases as $code) {
			$cleanSec->createDirective('HostAliases', $code);
		}
		$cleanSec->createDirective('URLAliasFile', $this->URLAliasFile);
		$cleanSec->createDirective('ReferrerFilterMode', $this->ReferrerFilterMode);

        $reportsSec =& $root->createSection('Reports');
		$reportsSec->createDirective('GraphNReferrerPairs', $this->GraphNReferrerPairs);
		$reportsSec->createDirective('GraphNSessions', $this->GraphNSessions);
		$reportsSec->createDirective('OutputDir', $this->OutputDir);
		$reportsSec->createDirective('ContinuousMode', $this->ContinuousMode);
		foreach ($this->SessionRequireURLs as $url) {
			$cleanSec->createDirective('SessionRequireURLs', $url);
		}

        // write out
        $result = $c->writeConfig($outPath, STATVIZ_CONF_TYPE);
        if (PEAR::isError($result)) {
            die("Error writing configuration: " . $result->getMessage() . "\n" . $result->getUserInfo() . "\n");
        }

        print "Wrote conf file to $outPath.\n";
    }
}

class statviz
{
    var $conf;              // reference to the configuration to use
    var $urlaliases;        // hash of all URLaliases loaded as per conf file, if any

    // runtime stuff shared by multiple functions
    var $logH;              // the handle to the log file

    // caches
    var $cacheNeedToCheckSkipURLs;  // check for conf->SkipURLs?
    var $cacheAcceptedCodes;        // cache of accepted HTTP response codes
    var $cacheSkipExts;             // cache of skipped extensions
    var $cacheBotRegex;             // cache of bot-skipping regex pattern
    var $cacheAllDomainNames;       // cache of all domains names for host-based hit inclusion
    var $cacheSessionRequiredURLs;  // cache of all SessionRequireURLs

    // Analysis vars
    var $lineNum;           // current line number of input being procesed
    var $goodlinecount;     // total number of analyzed lines considered "clean"
    var $skippedlinecount;  // hash with various categories; bot, ext, url, host, and status
    var $dateRange;         // date range of hits analyzed: start and end
    var $continuousModeUpdatedSIDs; // list of session ids updated since last processing
    var $pairs;             // hash with all ref->url pairs
    var $sessionTracks;     // array with all session tracks

    function statviz(&$conf)
    {
        $this->conf =& $conf;
        $this->urlaliases = array();

        $this->logH = NULL;
        
        // processing stats
        $this->lineNum = 0;
        $this->goodlinecount = 0;
        $this->skippedlinecount = array('bot' => 0, 'ext' => 0, 'url' => 0, 'host' => 0, 'status' => 0);
        $this->dateRange = array('start' => '', 'end' => '');
        $this->continuousModeUpdatedSIDs = array();

        // report workspaces
        $this->pairs = array();
        $this->sessionTracks = array();
    }

    // URLAliases are a list of "pretty print names" for various URLs. Great for making dynamic pages like: /page.php/1234 more readable on reports.
    function loadURLAliases()
    {
        $this->urlaliases = array();
        // add one for "direct" access -- do it first so people could override if they want in their custom file.
        $this->urlaliases['-'] = 'Direct Link';

        // load URLAliasFile if needed
        if ($this->conf->URLAliasFile) {
            $urlAliasEntries = file($this->conf->URLAliasFile);
            if ($urlAliasEntries === FALSE) die("Couldn't read URLAliasFile: {$this->conf->URLAliasFile}\n");

            foreach ($urlAliasEntries as $line) {
                $url = $alias = NULL;
                @list($url, $alias) = explode("\t", $line);
                if ($url and $alias) {
                    $this->urlaliases[$url] = chop($alias);
                }
            }
        }
    }

    function get_all_hostnames()
    {
        // calcualte all (internal) hostnames for this report.
        $allDomainNames = $this->conf->HostAliases;
        if ($this->conf->Domain)
            array_push($allDomainNames, $this->conf->Domain);
        $allDomainNames = array_map('strtolower', $allDomainNames);
        return $allDomainNames;
    }

    /*
     * For speed, we prepare caches of a bunch of items that need calculation.
     */
    function prepareCaches()
    {
        $this->cacheNeedToCheckSkipURLs = count($this->conf->SkipURLs) > 0;

        $this->cacheAcceptedCodes = array();
        foreach ($this->conf->AcceptHTTPCodes as $code) {
            $this->cacheAcceptedCodes[$code] = 1;
        }

        $this->cacheSkipExts = array();
        foreach ($this->conf->SkipExts as $skipExt) {
            $this->cacheSkipExts[$skipExt] = 1;
        }

        $this->cacheBotRegex = '/' . join('|', $this->conf->SkipBotUATokens) . '/i';

        $this->cacheAllDomainNames = array();
        foreach ($this->get_all_hostnames() as $hostname) {
            $this->cacheAllDomainNames[$hostname] = 1;
        }

        foreach ($this->conf->SessionRequireURLs as $url) {
            $this->cacheSessionRequiredURLs[$url] = 1;
        }

        $this->loadURLAliases();
    }

    // will return an array with the parsed log file, or FALSE if EOF.
    function get_next_log_line()
    {
        // open log
        if ($this->logH == NULL) {
            if (!$this->conf->LogFilePath) $this->dieErr("No logfile specified.");
            $this->logH = fopen($this->conf->LogFilePath, 'r');
            if (!$this->logH) $this->dieErr("Couldn't open logfile: {$this->conf->LogFilePath}");
        }
        $line = fgetcsv($this->logH, 5000, " ", '"');

        return $line;
    }

    function run()
    {
        // print config
        print "The config being used for this execution follows:\n";
        print_r($this->conf);

        // prepare caches
        $this->prepareCaches();

        // reset counters and such
        $this->lineNum = 0;
        $this->goodlinecount = 0;
        $this->skippedlinecount = array('bot' => 0, 'ext' => 0, 'url' => 0, 'host' => 0, 'status' => 0);
        $this->dateRange = array('start' => '', 'end' => '');

        // lastReportGoodLineCount - so we don't re-create reports every click
        $lastReportGoodLineCount = 0;

        print "Beginning to read log.\n";
        while ( ($line = $this->get_next_log_line()) !== FALSE) {
            $this->lineNum++;
            //print "read log line {$this->lineNum}: " . join (' ', $line ) . " \n";

            if ($this->lineNum == 1) {
                print "Line 1 parsing results... please check for accuracy!:\n";
                if ($this->conf->LogSessIDColumn) print " SessIDColumn ({$this->conf->LogSessIDColumn}): {$line[$this->conf->LogSessIDColumn]}.\n";
                if ($this->conf->LogURLColumn) print " URLColumn ({$this->conf->LogURLColumn}): {$line[$this->conf->LogURLColumn]}\n";
                if ($this->conf->LogRefColumn) print " RefColumn ({$this->conf->LogRefColumn}): {$line[$this->conf->LogRefColumn]}\n";
                if ($this->conf->LogDTSColumn) print " DTSColumn ({$this->conf->LogDTSColumn}): {$line[$this->conf->LogDTSColumn]} {$line[$this->conf->LogDTSColumn+1]}\n";
                if ($this->conf->LogUAColumn) print " UAColumn ({$this->conf->LogUAColumn}): {$line[$this->conf->LogUAColumn]}\n";
                if ($this->conf->LogVHostColumn) print " VHostColumn ({$this->conf->LogVHostColumn}): {$line[$this->conf->LogVHostColumn]}\n";
                if ($this->conf->LogStatusColumn) print " StatusColumn ({$this->conf->LogStatusColumn}): {$line[$this->conf->LogStatusColumn]}\n";
                print "\n";
            }

            $this->processLogLine($line);

            if ($this->conf->ContinuousMode and ($this->goodlinecount != $lastReportGoodLineCount) and ( $this->goodlinecount % $this->conf->ContinuousMode == 0 )) {
                // run reports
                $this->process_pairs();
                $this->process_sessions();

                // track last run
                $lastReportGoodLineCount = $this->goodlinecount;
            }

            // periodic reporting of progress...
            if ($this->lineNum % 5000 == 0)
                print "Processed {$this->lineNum} lines...\n";
            }
        print "Log ended.\n";

        // print final stats
        foreach ($this->skippedlinecount as $skipType => $skipCount) {
            print "Skipped {$skipCount} lines because of {$skipType}.\n";
        }
        print "Kept {$this->goodlinecount} lines.\n";

        if ($this->conf->ContinuousMode == 0) {
            // run reports
            $this->process_pairs();
            $this->process_sessions();
        }

        // clean up
        $this->cleanup();
    }

    function processLogLine(&$line)
    {
        $debugClean = 0;

        $debugClean && print "Processing line: {$this->lineNum}\n";

        // assume we'll keep the line unless we find a reason not to!
        $keepLine = true;

        // RUN various tests to see if we should keep the line.
        // for performance, do the easiest / most frequent excluders first

        // SkipExts
        $extMatches = array();
        if (preg_match('/\.(\w{1,6})|\.(\w{1,6}\w)(?=\/.*)/', $line[$this->conf->LogURLColumn], $extMatches)) {
            $ext =& $extMatches[1];
            if (isset($this->cacheSkipExts[$ext])) {
                $debugClean && print "Skipping ext: $ext\n";
                $this->skippedlinecount['ext']++;
                $keepLine = false;
            }
        }

        // skip bots
        if (isset($line[$this->conf->LogUAColumn])) {
            if (preg_match($this->cacheBotRegex, $line[$this->conf->LogUAColumn]) > 0) {
                $debugClean && print "Skipping bot: {$line[$this->conf->LogUAColumn]}\n";
                $this->skippedlinecount['bot']++;
                $keepLine = false;
            }
        } else {
            print "Warning: no user agent column in line# {$this->lineNum}\n";
        }

        // skip hits that aren't in our list of hosts -- this is an optional process
        if ($this->conf->SkipNonDeclaredHosts and $this->conf->LogVHostColumn) {    // is the option turned on, and does the log have the information in it?
            if (isset($line[$this->conf->LogVHostColumn])) {    // do we have VHost info in this line of the log?
                if (!isset($this->cacheAllDomainNames[strtolower($line[$this->conf->LogVHostColumn])])) {   // is the vhost in the list of declared hosts?
                    $debugClean && print "Skipping hit to another vhost ({$line[$this->conf->LogVHostColumn]}).\n";
                    $this->skippedlinecount['host']++;
                    $keepLine = false;
                }
            } else {
                print "Warning: no Virtual Host column in line# {$this->lineNum}\n";
            }
        }

        // skip all status codes but accepted ones
        if (!isset($this->cacheAcceptedCodes[$line[$this->conf->LogStatusColumn]])) {
            $debugClean && print "Skipping status code: {$line[$this->conf->LogStatusColumn]}\n";
            $this->skippedlinecount['status']++;
            $keepLine = false;
        }

        // SkipURLs
        if ($this->cacheNeedToCheckSkipURLs) {
            $urlMatches = array();
            $logLine =& $line[$this->conf->LogURLColumn];
            if (preg_match("/^\w+ ([^? ]*).*HTTP\/1..$/", $logLine, $urlMatches) == 0) $this->dieErr("couldn't figure out url from: " . $line[$this->conf->LogURLColumn]);
            $lineURL =& $urlMatches[1];
            $skipLineURL = false;
            foreach ($this->conf->SkipURLs as $skipURL) {
                if (stristr($lineURL, $skipURL)) {
                    $skipLineURL = true;
                    break;
                } 
            }

            if ($skipLineURL) {
                $debugClean && print "Skipping url: $lineURL\n";
                $this->skippedlinecount['url']++;
                $keepLine = false;
            }
        }

        // so, are we keeping the line?
        if ($keepLine) {
            $this->goodlinecount++;
            $debugClean && print "Keeping line {$this->lineNum}.\n";
            $this->analyze_line($line);
        } else {
            $debugClean && print "Skipping line {$this->lineNum}\n";
        }
        // add the line to the reports

    }

    function analyze_line(&$line)
    {
        // analyze line for the various reports we're tracking

        // get all hostnames from prefenencs that are supposed to be "us"
        $allDomainNames = $this->get_all_hostnames();
        if ($this->conf->LogVHostColumn and isset($line[$this->conf->LogVHostColumn])) {
            // add the hostname for this hit to the list also! this is mostly for web server hosts that are "catchall" accounts for many domain names
            array_push($allDomainNames, $line[$this->conf->LogVHostColumn]);
        }

        // maintain date range stats
        // grab start date
        if (!$this->dateRange['start']) {
            $this->dateRange['start'] = substr($line[$this->conf->LogDTSColumn], 1);
        }
        // keep track of "last"
        $this->dateRange['end'] = substr($line[$this->conf->LogDTSColumn], 1);

        // maintain ref->url pairs histogram.

        // normalize URLs
        // get URL
        $urlMatches = array();
        $logLineURL =& $line[$this->conf->LogURLColumn];
        // normalize:: chop querystring
        if (preg_match("/^\w+ ([^? ]*).*HTTP\/1..$/", $logLineURL, $urlMatches) == 0) $this->dieErr("couldn't figure out url from: " . $logLineURL);
        $lineURL =& $urlMatches[1];
        // normalize:: urlaliases
        if (isset($this->urlaliases[$lineURL])) {
            $lineURL = $this->urlaliases[$lineURL];
        }
        // normalize:: indexes
        foreach ($this->conf->IndexPages as $index) {
            $indexMatches = array();
            if (preg_match("/^(.*\/){$index}$/", $lineURL, $indexMatches)) {
                $lineURL = $indexMatches[1];
            }
        }


        // get REF
        $lineRef =& $line[$this->conf->LogRefColumn];
        // chop querystring
        if (preg_match("/^([^? ]*).*$/", $lineRef, $urlMatches) == 0) $this->dieErr("couldn't figure REF URI without Query String in url: " . $lineRef);
        $lineRef =& $urlMatches[1];
        // normalize REF's for HostAliases -- this will include the virtual hose serving this hit, even if it isn't specified in the HostAliases or Domain config
        foreach ($allDomainNames as $alias) {
            $refMatches = array();
            // case insensitive!! Domains are not case-sensitive (although alias is already lower, lineRef might not be)
            if (preg_match("/http[s]?:\/\/[^\/]*{$alias}(.*)/i", $lineRef, $refMatches)) {
                $lineRef = $refMatches[1];
                break;
            }
        }
        // throw away pairs based on ReferrerFilterMode -- skip external refs if we're in 'internal' mode.
        if ($this->conf->ReferrerFilterMode == 'internal' and preg_match('/http[s]?:\/\//', $lineRef)) {
            // print "Skipping external referrer ({$lineRef}).\n";
            continue;
        }

        // normalize:: urlaliases
        if (isset($this->urlaliases[$lineRef])) {
            $lineRef = $this->urlaliases[$lineRef];
        }
        // normalize:: indexes
        if (!preg_match('/http[s]?:\/\//', $lineRef)) {
            foreach ($this->conf->IndexPages as $index) {
                $indexMatches = array();
                if (preg_match("/^(.*\/){$index}$/", $lineRef, $indexMatches)) {
                    //print "Normalized internal referrer: $lineRef to {$indexMatches[1]}\n";
                    $lineRef = $indexMatches[1];
                }
            }
        }

        // MANAGE PAIRS DATA: increment ref->url pair. create if needed.
        if ($this->conf->GraphNReferrerPairs) {
            $pairKey = "$lineRef -> $lineURL";
            if (!isset($this->pairs[$pairKey])) $this->pairs[$pairKey] = 0;
            $this->pairs[$pairKey]++;
        }

        // MANAGE SESSION TRACKS DATA
        if ($this->conf->GraphNSessions) {
            // skip non-numeric SIDs
            $sessID = $line[$this->conf->LogSessIDColumn];
            if ($sessID != '-') {
                $dts = $line[$this->conf->LogDTSColumn] . ' ' . $line[$this->conf->LogDTSColumn + 1]; // append DTS and TZ
                $dts = substr($dts, 1, strlen($dts) - 2);
                if (!isset($this->sessionTracks[$sessID]))
                    $this->sessionTracks[$sessID] = array();
                array_push($this->sessionTracks[$sessID], array('ref' => $lineRef, 'url' =>  $lineURL, 'dts' => $dts));
                if ($this->conf->ContinuousMode) {
                    $this->continuousModeUpdatedSIDs[$sessID] = 1;
                }
            }
        }

    }

    function process_pairs()
    {
        print "Generating top links report.\n";

        // sort pairs
        $ok = arsort($this->pairs, SORT_NUMERIC);
        if (!$ok) $this->dieErr("Failed to sort pairs array.");

        // report!
        $graph = "digraph navipairs {
            graph [splines=true overlap=false rankdir=TB size=\"10,8\"]
            node [style=filled]
            edge [style=bold, arrowsize=2.0]
                ";

        // initialize counters
        $numPairs = 0;
        $maxPairCount = NULL;   // for managing relative weight of lines
        $minPairCount = NULL;   // for managing relative weight of lines
        $maxHitCount = 0;
        $maxRefCount = 0;
        $topPairClicks = 0;
        $allPairClicks = 0;
        $hitCounts = array();   // list of hit counts for each URL
        $refCounts = array();   // list of ref counts for each referrer
        foreach ($this->pairs as $pairKey => $pairCount) {
            // separate out the ref and url part of the pair
            list($pairRef, $pairURL) = explode(' -> ', $pairKey);

            // keep track of some stats -- this section (above the "continue" line) track stats for only the pairs being shown in the report.
            // count hits for each URL and referrer; the data will be used to display relative traffic of the nodes
            if (!isset($hitCounts[$pairURL])) $hitCounts[$pairURL] = 0;
            $hitCounts[$pairURL] += $pairCount;
            if (!isset($refCounts[$pairRef])) $refCounts[$pairRef] = 0;
            $refCounts[$pairRef] += $pairCount;
            // also keep track of max's
            if ($hitCounts[$pairURL] > $maxHitCount) $maxHitCount = $hitCounts[$pairURL];
            if ($refCounts[$pairRef] > $maxRefCount) $maxRefCount = $refCounts[$pairRef];

            // total number of clicks for all pairs
            $allPairClicks += $pairCount;
            if ($numPairs++ > $this->conf->GraphNReferrerPairs) continue;           // skip counting once we've reached our maximum of things to graph

            // keep track of some stats -- this section (below the "continue" line) track stats for all of the traffic analyed.
            // total number of clicks by the top pairs
            $topPairClicks += $pairCount;
            
            // max and min counts of the pairs being show
            if ($maxPairCount == NULL) $maxPairCount = $minPairCount = $pairCount;
            if ($pairCount < $minPairCount) $minPairCount = $pairCount;
            
            // draw connections
            $relWeight255 = ceil(max($pairCount / $maxPairCount, .10) * 255);           // relative weight from 0-255 (with a minimum so lines don't disappear)
            $redWeight = str_pad(dechex($relWeight255), 2, "0", STR_PAD_LEFT);          // make more links "hotter"
            $blueWeight = str_pad(dechex(255 - $relWeight255), 2, "0", STR_PAD_LEFT);   // make less links "cooler"
            $alphaWeight = str_pad(dechex($relWeight255), 2, "0", STR_PAD_LEFT);        // make less links "lighter"
            $graph .= "\"$pairRef\" -> \"$pairURL\" [label=\"$pairCount\", fontcolor=\"#{$redWeight}00{$blueWeight}\" color=\"#{$redWeight}00{$blueWeight}{$alphaWeight}\"];\n";
        }

        // manifest the referrer nodes, then the hit/page nodes.
        // but since these nodes will appear later in the graph, they will override the previous declaration.
        $manifestedNodes = array(); // for keeping track of which nodes have been manifested (to prevent dupes)
        $numPairs = 0;
        foreach ($this->pairs as $pairKey => $pairCount) {
            if ($numPairs++ > $this->conf->GraphNReferrerPairs) continue;

            // separate out the ref and url part of the pair
            list($pairRef, $pairURL) = explode(' -> ', $pairKey);

            // manifest the referrer nodes, but only once!
            if (!isset($manifestedNodes[$pairRef])) {
                // use one color for external, another for internal nodes
                $external = false;
                if ( preg_match('/http[s]?:\/\//', $pairRef) or $pairRef == 'Direct Link') {
                    $external = true;
                }
                $relWeight255 = ceil(max($refCounts[$pairRef] / $maxRefCount, .30) * 255);          // relative weight from 0-255 (with a minimum so nodes don't disappear)
                $alphaWeight = str_pad(dechex($relWeight255), 2, "0", STR_PAD_LEFT);                // make less referrer leads "lighter"
                $referrals_str = "({$refCounts[$pairRef]} referral" . ($refCounts[$pairRef] == 1 ? '' : 's') . ")\\n";
                if (isset($hitCounts[$pairRef])) {
                    $hits_str = "({$hitCounts[$pairRef]} hit" . ($hitCounts[$pairRef] == 1 ? '' : 's') . ")";
                } else {
                    $hits_str = '';
                }
                if ($external) {
                    $manifestedNodes[$pairRef] = "\"$pairRef\" [label=\"{$pairRef}\\n{$referrals_str}\", color=\"#999966{$alphaWeight}\", shape=box];\n";
                } else {
                    $manifestedNodes[$pairRef] = "\"$pairRef\" [label=\"{$pairRef}\\n{$referrals_str}{$hits_str}\", color=\"#330099{$alphaWeight}\"];\n";
                }
            }

            // represent intensity as background transparency
            $relWeight255 = ceil(max($hitCounts[$pairURL] / $maxHitCount, .05) * 255);           // relative weight from 0-255 (with a minimum so nodes don't disappear)
            $redWeight = str_pad(dechex($relWeight255), 2, "0", STR_PAD_LEFT);          // make more links "hotter"
            $blueWeight = str_pad(dechex(255 - $relWeight255), 2, "0", STR_PAD_LEFT);   // make less links "cooler"
            $alphaWeight = str_pad(dechex($relWeight255), 2, "0", STR_PAD_LEFT);        // make less links "lighter"
            if (isset($refCounts[$pairURL])) {
                $referrals_str = "({$refCounts[$pairURL]} referral" . ($refCounts[$pairURL] == 1 ? '' : 's') . ")\\n";
            } else {
                $referrals_str = '';
            }
            $hits_str = "({$hitCounts[$pairURL]} hit" . ($hitCounts[$pairURL] == 1 ? '' : 's') . ")";
            $manifestedNodes[$pairURL] = "\"$pairURL\" [label=\"{$pairURL}\\n{$referrals_str}{$hits_str}\", color=\"#{$redWeight}00{$blueWeight}{$alphaWeight}\"];\n";
        }
        // print out nodes
        foreach ($manifestedNodes as $nodeURL => $nodeGraph) {
            $graph .= $nodeGraph;
        }

        // show legend
        $graph .= "graph [labelloc=t, label=\"{$this->conf->Domain} Top {$this->conf->GraphNReferrerPairs} Referrer Graph\\n\\nDates Analyzed:\\nStart: {$this->dateRange['start']}\\nEnd: {$this->dateRange['end']}\\nTop Pairs account for {$topPairClicks} of {$allPairClicks} clicks (" . round( ($topPairClicks / $allPairClicks) * 100, 2) . "%)\\n\\nLegend:\\nReferrers shown as brown boxes; intensity shows relative number of referrals.\\nSite Pages shown in ovals, colored from red to blue, representing popularity of page.\\nHit and Referral Counts are totals for period, not just those shown on graph.\\n\\n\"]";
        $graph .= "}";

        $this->dumpreport('pairs', $graph);
    }

    function session_matches_config($sid)
    {
        $track =& $this->sessionTracks[$sid];

        foreach ($track as $click) {
            if (isset($this->cacheSessionRequiredURLs[$click['url']])) return true;
        }

        return false;
    }

    function process_sessions()
    {
        print "Generating top session clickstream reports.\n";

        // in ContinuousMode we dump ALL sessions that have changed...
        // otherwise we dump the top GraphNSessions sessions by size
        $sessionsToReport = array();
        if ($this->conf->ContinuousMode == 0) {
            $sessionCounts = array();
            foreach ($this->sessionTracks as $sid => $track) {
                $sessionCounts[$sid] = count($track);
            }
            $ok = arsort($sessionCounts, SORT_NUMERIC);
            if (!$ok) $this->dieErr("Failed to sort sessionCounts array.");
            $countsRemaining = $this->conf->GraphNSessions;
            foreach ($sessionCounts as $sid => $count) {
                if ($countsRemaining and $this->session_matches_config($sid)) {     // only include sessions matching certain criteria
                    $countsRemaining--;
                    //print "Keeping session track $sid ($count clicks, $countsRemaining sessions more to report) .\n";
                    array_push($sessionsToReport, $sid);
                } else {
                    //print "Skiping session track $sid ($count clicks)\n";
                }
            }
        } else {
            // grab list and then reset it
            $sessionsToReport = $this->continuousModeUpdatedSIDs;
            $this->continuousModeUpdatedSIDs = array();
            
        }
        
        $trackNum = 1;
        foreach ($sessionsToReport as $sid) {
            //print "Creating session graph for sid: $sid\n";
            $track =& $this->sessionTracks[$sid];
            $graph = "digraph sessionTrack{$trackNum} {
                graph [splines=true overlap=false rankdir=TB label=\"{$this->conf->Domain} Session Track #{$trackNum}\" labelloc=t]
                node [style=filled]
                edge [style=bold]
                    ";

            $manifestedNodes = array(); // for keeping track of which nodes have been manifested (to prevent dupes)
            $clickNum = 1;
            $lastClick = NULL;
            $clickCount = count($track);
            $lineColors = Gradient('000066', 'CC9900', $clickCount);
            foreach ($track as $click) {
                // manifest nodes -- only referrers can be external
                // make sure both URLs and REFs are manifested; but don't duplicated
                if (!isset($manifestedNodes[$click['ref']])) {
                    $manifestedNodes[$click['ref']] = true;
                    // use one color for external, another for internal nodes
                    $external = false;
                    if ( preg_match('/http[s]?:\/\//', $click['ref']) or $click['ref'] == 'Direct Link') {
                        $external = true;
                    }
                    if ($external) {
                        $graph .= "\"{$click['ref']}\" [color=khaki4];\n";
                    } else {
                        $graph .= "\"{$click['ref']}\" [color=lightblue2];\n";
                    }
                }
                // don't re-manifest nodes once done, unless we're on the LAST NODE, because it might be the exit node and be manifested already, thus it'd be the wrong color.
                if (!isset($manifestedNodes[$click['url']]) or $clickNum == $clickCount) {
                    $manifestedNodes[$click['url']] = true;
                    // show entry page in green
                    switch ($clickNum) {
                        case 1:
                            $hitNodeColor = "green";
                            break;
                        case $clickCount:
                            $hitNodeColor = "red";
                            break;
                        default:
                            $hitNodeColor = "lightblue2";
                    }
                    // show exit page in red
                    $graph .= "\"{$click['url']}\" [color={$hitNodeColor}];\n";
                }

                // draw "back" arrow if our referrer isn't the previous url!
                if ($lastClick and $click['ref'] != $lastClick['url']) {
                    //print "$trackNum::$clickNum:: we didn't get here ({$click['url']}) from the previous page (" . $lastClick['url'] . ")!\n";
                    //print "$trackNum::$clickNum:: HERE url={$click['url']}, ref={$click['ref']}\n";
                    //print "$trackNum::$clickNum:: LAST url={$lastClick['url']}, ref={$lastClick['ref']}\n";
                    // go back up the track until we find our referrer -- if we don't then it's a re-entrance
                    // start at clickNum - 3:
                    //      this click is $clickNum - 1
                    //      we already checked $clickNum - 2 with $lastClick
                    $foundBackTrack = false;
                    for ($trackI = $clickNum - 3; $trackI >= 0; $trackI--) {
                        //print "$trackNum::$clickNum:: ($trackI) url={$track[$trackI]['url']}, ref={$track[$trackI]['ref']}\n";
                        if ($click['ref'] == $track[$trackI]['url']) {
                            // found it! draw back arrow
                            $foundBackTrack = true;
                            //print "$trackNum::$clickNum:: found our previous page! (" . $track[$trackI]['url'] . ")\n";
                            $graph .= "\"{$lastClick['url']}\" -> \"{$track[$trackI]['url']}\" [label=\"" . ($clickNum - 1) . " BACK\", color=\"#{$lineColors[$clickNum-2]}\", style=\"dashed\"];\n";
                            break;
                        }
                    }
                    if (!$foundBackTrack) {
                        // couldn't find the back-track.. was a re-entrance, or back-out to external referrer. re-draw.
                        $graph .= "\"{$lastClick['url']}\" -> \"{$click['ref']}\" [label=\"" . ($clickNum - 1) . " BACK\", color=\"#{$lineColors[$clickNum-2]}\", style=\"dashed\"];\n";
                    }
                }
                
                // draw link
                $usecs = from_apachedate($click['dts']);
                if ($usecs == -1) $this->dieErr("Cannot convert dts ({$click['dts']}) into unix secconds.");
                $prettyDate = date('g:i:s A', $usecs);
                $graph .= "\t\"{$click['ref'] }\" -> \"{$click['url']}\" [label=\"{$clickNum} - {$prettyDate}\", color=\"#{$lineColors[$clickNum-1]}\"];\n";

                // keep track of things
                $lastClick = $click;
                $clickNum++;
            }
            // display session length and sid for research
            $sessionLength = datediff('ms', from_apachedate($track[0]['dts']), from_apachedate($track[$clickCount-1]['dts']), true);
            $graph .= "\"Session Length: {$sessionLength} minutes. {$clickCount} Clicks.\\nSessionID: {$sid}\" [shape=box];\n";
            $graph .= "}\n\n";

            $this->dumpreport("track-{$sid}", $graph);
            $trackNum++;
        }
    }

    function cleanup()
    {
        if ($this->logH) fclose($this->logH);
    }

    function dumpreport($fileext, &$msg)
    {
        // check output dir
        $outDirPrefix = '';
        if ($this->conf->OutputDir) {
            @mkdir($this->conf->OutputDir);
            if (!is_dir($this->conf->OutputDir)) $this->dieErr("Output dir is not a directory: {$this->conf->OutputDir}.");
            $outDirPrefix = $this->conf->OutputDir . '/';
        }
        $outpath = $outDirPrefix . $fileext . '.dot';
        print "Writing report '{$fileext}.dot' to $outpath\n";
        $outH = fopen($outpath, 'w');
        if (!$outH) $this->dieErr("Couldn't open output file: $outpath.");
        fwrite($outH, $msg);
        fclose($outH);
    }

    function dieErr($msg)
    {
        $this->cleanup();
        die("$msg\n");
    }
}


function from_apachedate($date)
{
    list($d, $M, $y, $h, $m, $s, $z) = sscanf($date, "%2d/%3s/%4d:%2d:%2d:%2d %5s");
    return strtotime("$d $M $y $h:$m:$s $z");
}

// calculate the RBG in decimal for an hex color 
function SeparateRBG($col) 
{ 
    $Color["R"]= hexdec(substr($col, 0, 2 )); 
    $Color["B"] = hexdec(substr($col, 2, 2)); 
    $Color["G"] = hexdec(substr($col, -2)); 

    return $Color ; 
} 

// return $GradientColor an array that contien the final gradient 
function Gradient($coldeb, $colfin, $n) 
{ 
    $Color["deb"] = SeparateRBG($coldeb); 
    $Color["fin"] = SeparateRBG($colfin); 
    $rbg = array('R', 'B', 'G'); 

    // calculate the red, the bleu, and the green gradient 
    foreach ($rbg as $RBG) 
    { 
        $Color["enc"][$RBG] = floor( (($Color["fin"][$RBG]) - ($Color["deb"][$RBG])) / $n ); 
        for ($x = 0 ; $x < $n ; $x++) { 
            $Color["gradient"][$RBG][$x] = dechex($Color["deb"][$RBG] + ($Color["enc"][$RBG] * $x)); 
            if (strlen(strval($Color["gradient"][$RBG][$x])) < 2) { 
                $Color["gradient"][$RBG][$x] = '0' . $Color["gradient"][$RBG][$x]; 
            } 
        } 
    } 
    // build the final gradient array 
    for ($i = 0 ; $i < $n ; $i++) 
    { 
        $GradientColor[] = $Color["gradient"]["R"][$i] . $Color["gradient"]["B"][$i] . $Color["gradient"]["G"][$i]; 
    } 
    return $GradientColor; 
} 

function datediff($interval, $datefrom, $dateto, $using_timestamps = false)
{
    /*
    $interval can be:
    yyyy - Number of full years
    q - Number of full quarters
    m - Number of full months
    y - Difference between day numbers
        (eg 1st Jan 2004 is "1", the first day. 2nd Feb 2003 is "33". The datediff is "-32".)
    d - Number of full days
    w - Number of full weekdays
    ww - Number of full weeks
    h - Number of full hours
    n - Number of full minutes
    s - Number of full seconds (default)
    ms - Number of minutes and seconds
    */
    if (!$using_timestamps) {
        $datefrom = strtotime($datefrom, 0);
        $dateto = strtotime($dateto, 0);
    }
    $difference = $dateto - $datefrom; // Difference in seconds

    switch($interval) {

        case 'yyyy': // Number of full years

            $years_difference = floor($difference / 31536000);
            if (mktime(date("H", $datefrom), date("i", $datefrom), date("s", $datefrom), date("n", $datefrom), date("j", $datefrom), date("Y", $datefrom)+$years_difference) > $dateto) {
                $years_difference--;
            }
            if (mktime(date("H", $dateto), date("i", $dateto), date("s", $dateto), date("n", $dateto), date("j", $dateto), date("Y", $dateto)-($years_difference+1)) > $datefrom) {
                $years_difference++;
            }
            $datediff = $years_difference;
            break;

            case "q": // Number of full quarters

                $quarters_difference = floor($difference / 8035200);
            while (mktime(date("H", $datefrom), date("i", $datefrom), date("s", $datefrom), date("n", $datefrom)+($quarters_difference*3), date("j", $dateto), date("Y", $datefrom)) < $dateto) {
                $months_difference++;
            }
            $quarters_difference--;
            $datediff = $quarters_difference;
            break;

            case "m": // Number of full months

                $months_difference = floor($difference / 2678400);
            while (mktime(date("H", $datefrom), date("i", $datefrom), date("s", $datefrom), date("n", $datefrom)+($months_difference), date("j", $dateto), date("Y", $datefrom)) < $dateto) {
                $months_difference++;
            }
            $months_difference--;
            $datediff = $months_difference;
            break;

        case 'y': // Difference between day numbers

            $datediff = date("z", $dateto) - date("z", $datefrom);
            break;

            case "d": // Number of full days

                $datediff = floor($difference / 86400);
            break;

            case "w": // Number of full weekdays

                $days_difference = floor($difference / 86400);
            $weeks_difference = floor($days_difference / 7); // Complete weeks
            $first_day = date("w", $datefrom);
            $days_remainder = floor($days_difference % 7);
            $odd_days = $first_day + $days_remainder; // Do we have a Saturday or Sunday in the remainder?
            if ($odd_days > 7) { // Sunday
                $days_remainder--;
            }
            if ($odd_days > 6) { // Saturday
                $days_remainder--;
            }
            $datediff = ($weeks_difference * 5) + $days_remainder;
            break;

            case "ww": // Number of full weeks

                $datediff = floor($difference / 604800);
            break;

            case "h": // Number of full hours

                $datediff = floor($difference / 3600);
            break;

            case "n": // Number of full minutes

                $datediff = floor($difference / 60);
            break;

            case "ms":
                $mins = floor($difference / 60);
                $secs = floor($difference % 60);
                $secs = str_pad($secs, 2, '0', STR_PAD_LEFT);
                $datediff = "$mins:$secs";
                break;
        default: // Number of full seconds (default)

            $datediff = $difference;
            break;
    }

    return $datediff;

}

?>
