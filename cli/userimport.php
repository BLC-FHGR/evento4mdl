<?php

// Always inform Moodle that a cli script is running.
define('CLI_SCRIPT', true);

/** **********************************************************************
 * Messages and Constants
 *  **********************************************************************  */

$createCourse = 0;
$createUser   = 0;
$enrolUser    = 0;

// Error Messages
$ERR_NO_MOODLE = <<<EONoMoodle
! Could not find a moodle instance
! Ensure that this script is installed with moodle

EONoMoodle;

// Help Message
$MSG_HELP = <<<EOHelp

Synchronises Module and Student Information from an Evento System.

Usage:

  php userimport.php -l -c -C -R -e -p -m -M=idpattern -s=courseid

Options

  -c - creates non-existing modules.

  -C - registers non-existing participants to the system

  -e - enrols participants if not already course members

  -i - displays information only.
       this option shows all relevant data provided by evento. It unsets
       -e, -c, -C and it implies -l. Other than the plain -l option it
       it will not skip unavailable courses or non-created students.

  -l - displays the selection

  -m[=module_nummer]  - select modules
       if a module "nummer" is provided, then it selects only the participants
       for the provided module

  -M=module_nummer_pattern - select only matching modules
       the pattern matches only the module_nummer. If used with -p=progNummer,
       then it will filter only modules of the selected program.

  -p[=program_nummer] - display all active study programs.
       if a study program "nummer" is provided, then it selects only modules
       for the provided study program

  -r - display a brief report on the actions taken.

  -s - select module participants

  -v - display extensive debug mesages

  -? - displays this message


EOHelp;

/** **********************************************************************
 * Detect and tear up moodle
 *  **********************************************************************  */

$dirname = dirname(__FILE__);

// NOTE lib/moodlelib.php is a good indicator for moodle-based systems
while (!empty($dirname) &&
       $dirname !== "/" &&
       !file_exists($dirname . "/lib/moodlelib.php"))
{
    $dirname = dirname($dirname);
}

// die if not run within a moodle instance
if ($dirname === "/" ||
    !file_exists($dirname . "/lib/moodlelib.php"))
{
    cli_error($ERR_NO_MOODLE);
}

// include the local configuration
require('../config.php');

// set include path to moodle root
// place Moodle with lowest priority.
set_include_path(get_include_path() . PATH_SEPARATOR . $dirname);

// tear up moodle
global $CFG;

require('config.php');
require('lib/clilib.php');


/** **********************************************************************
 * Script Functions
 *  **********************************************************************  */

// Local Functions
function loadEventoJson($id="", $get="")
{
    $aEventoParam = array("id" => "moodle");
    if (!empty($id))
    {
        $aEventoParam['tx_htwmoodledata_pi1[id]'] = $id;
    }
    if (!empty($get))
    {
        $aEventoParam['tx_htwmoodledata_pi1[get]'] = $get;
    }

    $aParam = array();
    foreach ($aEventoParam as $k => $v)
    {
        $aParam[] = $k . '='. $v;
    }

    $json = file_get_contents(EVENTO_HOST . implode('&', $aParam));

    $retval = array();
    if (!empty($json))
    {
        try
        {
            $retval = json_decode($json);
        }
        catch (Exception $err)
        {
            // the only problem reported without $verbose set
            cli_problem($err->getMessage());
            return array();
        }
    }

    return $retval;
}

function checkModule($module, $program)
{
    global $DB;
    // first we check for the full module id
    if ($course = $DB->get_record("course", array("idnumber"=>$module->nummer)))
    {
        $module->moodle = $course;
        return true;
    }

    // figure out if the program is encoded in the module
    $regex = "/\." . preg_quote($program->nummer) . "/";
    list($modid) = preg_split($regex, $module->nummer);

    if ($course = $DB->get_record("course", array("shortname"=>$modid)))
    {
        $module->moodle = $course;
        return true;
    }

    return false;
}

function checkUser($user)
{
    global $DB;
    if (!$muser = $DB->get_record('user', array('username'=>$user->uuid)))
    {
        return null;
    }
    return $muser;
}

function createModule($module)
{
    global $DB, $createCourse;
    $createCourse++;

    $course = new stdClass();
    $course->id = "Fake Module";

    $module->moodleId = $course->id;
}

function registerUser($user, $v)
{
    global $CFG, $DB, $createUser;
    require_once($CFG->libdir . "/adminlib.php");
    require_once($CFG->libdir . "/filelib.php");
    require_once("tag/lib.php");
    require_once("user/editlib.php");
    require_once("user/profile/lib.php");
    require_once("user/lib.php");

    if ($v)
        cli_problem("register user");

    $usernew = new stdClass();

    $usernew->auth = ACCOUNT_TYPE;
    $usernew->username = $user->uuid;
    $usernew->email = $user->mail;
    $usernew->firstname = $user->vorname;
    $usernew->lastname = $user->nachname;
    $usernew->idnumber = $user->id;

    $usernew->confirmed = 1;
    $usernew->interests = "";

    // Moodle wants more for valid users.
    $usernew->timecreated = time();

    $usernew->mnethostid = $CFG->mnet_localhost_id; // Always local user.
    $usernew->password = AUTH_PASSWORD_NOT_CACHED;  // because of Shibboleth

    // finally create the user.
    $usernew->id = user_create_user($usernew, false, false);

    if ($usernew->id > 0)
    {
        // moodle wants additional profile setups

        $usercontext = context_user::instance($usernew->id);

        // Update preferences.
        useredit_update_user_preference($usernew);

        if (!empty($CFG->usetags)) {
            useredit_update_interests($usernew, $usernew->interests);
        }

        // Update mail bounces.
        useredit_update_bounces($usernew, $usernew);

        // Update forum track preference.
        useredit_update_trackforums($usernew, $usernew);

        // Save custom profile fields data.
        profile_save_data($usernew);

        // Reload from db.
        $usernew = $DB->get_record('user', array('id' => $usernew->id));

        // not sure what this will do, but moodle wants it.
        \core\event\user_created::create_from_userid($usernew->id)->trigger();

        $createUser++;
        return $usernew;
    }

    if ($v)
        cli_problem("user not created " . json_encode($usernew));

    return null;
}

function initEnrolment($module, $v, $role)
{
    global $CFG;

    require_once('enrol/locallib.php');
    require_once('group/lib.php');
    require_once('enrol/manual/locallib.php');

    global $PAGE;

    $enroltype = "manual";
    $roletype  = "student";

    if (isset($role) &&
        !empty($role))
    {
        $roletype = $role;
    }

    if (!isset($module->moodle))
    {
        if ($v) cli_problem("moodle course not loaded!?!");
        return;
    }

    if ($v) cli_problem("init " . $module->moodle->id);

    $enrolObj = new stdClass();
    $manager  = new course_enrolment_manager($PAGE, $module->moodle);

    $instances = $manager->get_enrolment_instances();

    // find the $enroltype
    $enrolid = 0;
    foreach($instances as $id => $inst)
    {
        if ($inst->enrol === $enroltype)
        {
            $enrolid = $id;
            break;
        }
    }

    if (!array_key_exists($enrolid, $instances))
    {
        if ($v) cli_problem("fail to find enrolment plugin");
        if ($v && !isset($instances)) cli_problem("no instances found?");
        return;
    }

    $instance = $instances[$enrolid];

    // Do not allow actions on disabled plugins.
    $plugins  = $manager->get_enrolment_plugins(true);
    if (!isset($plugins[$instance->enrol]))
    {
        if ($v) cli_problem("enrolment plugin forbid enrolment?");
        return;
    }

    $plugin = $plugins[$instance->enrol];
    if (!$plugin->allow_enrol($instance))
    {
        if ($v) cli_problem("enrolment forbidden for module");
        return;
    }

    $enrolObj->manager  = $manager; // needed for unenrolment
    $enrolObj->instance = $instance;
    $enrolObj->plugin   = $plugin;

    // get the default role id
    $roles = $manager->get_all_roles();

    foreach($roles as $id => $role)
    {
        if ($role->shortname === $roletype)
        {
            $enrolObj->roleid = $id;
            $enrolObj->rolename = $roletype;
            break;
        }
    }

    // all users start at the same time.
    $today = time();
    $enrolObj->timestart = make_timestamp(date('Y', $today), date('m', $today), date('d', $today), 0, 0, 0);

    $module->enrol = $enrolObj;

    // get all users at once
    $nUsers = $manager->get_total_users();
    // the next call is potentially dangerous for very large groups.
    // the parameters are actually irrelevant, because we will process them anyways
    $users  = $manager->get_users("id",
                                  "ASC",
                                  0,
                                  $nUsers);
    $tUsers = array();

    // preprocess the userlist for faster enrolment checks
    foreach ($users as $user)
    {
        $tUsers[$user->id] = true;
    }

    $module->allUsers = $tUsers;
}

function checkEnrolment($module, $user, $v, $role)
{

    // always try to initialize the course context
    if (!property_exists($module, 'enrol') ||
        $module->enrol->rolename !== $role)
    {
        if ($v) cli_problem("initialize module framing");
        initEnrolment($module, $v, $role);
    }

    if (!isset($user))
    {
        return false;
    }

    if (isset($module->allUsers) && count($module->allUsers))
    {
        if (array_key_exists($user->id, $module->allUsers))
        {
            if ($v)
                cli_problem('user is already enrolled');

            $user->isEnrolled = true;
            return true;
        }
    }
    return false;
}

function enrolUser($module, $user, $v)
{
    global $enrolUser; // for stats

    if (isset($module->enrol))
    {
        if ($v) cli_problem("enrol user " . $user->id);

        if (!property_exists($user, 'isEnrolled'))
        {

            $roleid             = $module->enrol->roleid;
            cli_problem("enrol with role: " . $roleid);

            $recovergrades      = 0;
            $timeend            = 0;
            $timestart          = $module->enrol->timestart;
            $instance           = $module->enrol->instance;
            $plugin             = $module->enrol->plugin;

            $plugin->enrol_user($instance,
                                $user->id,
                                $roleid,
                                $timestart,
                                $timeend,
                                null,
                                $recovergrades);
            $enrolUser++;
        }
    }
}

function fetchPrograms($param)
{
    $l  = $param["list"];
    $v  = $param["verbose"];

    $p  = $param["programs"];
    $m  = $param["modules"];
    $mp = $param["spattern"];
    $s  = $param["students"];

    $prgs = array();

    if ($p && ($l || $v))
    {
        cli_heading("Programs");
    }

    $cohorts = loadEventoJSON();

    foreach($cohorts as $c)
    {
        if (gettype($p) === "string" &&
            $p !== $c->nummer)
        {
            // skip non requested cohort programs
            continue;
        }

        if ($p && ($l || $v))
        {
            echo $c->status . " : " .
                 $c->id     . " : " .
                 $c->nummer . " : " .
                 $c->bezeichnung .
                 "\n";
        }

        // return the requested programs if further processing is required
        if ($m || $s || $mp)
        {
            $prgs[] = $c;
        }
    }

    return $prgs;
}

function fetchModules($prgs, $param)
{
    $l  = $param["list"];
    $i  = $param["inform"];
    $v  = $param["verbose"];

    $m  = $param["modules"];
    $c  = $param["auto-create"];
    $mp = $param["spattern"];
    $s  = $param["students"];

    if (($m || $mp) && ($l || $v))
    {
        cli_heading("Modules");
    }

    if ($mp)
    {
        $regex = "/" . preg_quote($mp) . "/";
    }

    $mods = array();

    if ($v) cli_problem("process " . count($prgs) . " programs");

    foreach ($prgs as $p)
    {
        $modules = loadEventoJson($p->id, "module");

        foreach ($modules as $mod)
        {
            if ((gettype($m) == "string" &&
                 $m !== $mod->nummer) ||
                ($mp &&
                 !preg_match($regex, $mod->nummer)))
            {
                // skip non requested modules
                continue;
            }

            if (!checkModule($mod, $p))
            {
                if ($c)
                {
                    createModule($mod);
                }
                else if (!$i)
                {
                    if ($v) cli_problem("module " . $mod->nummer . " not found");
                    // cannot enrol anybody to non existing modules
                    continue;
                }
            }

            if ($s)
            {
                // return the module if further processing is needed
                $mods[] = $mod;
            }

            if (($m || $mp) && ($l || $v))
            {
                cli_problem("full module: " . json_encode($mod));

                echo $mod->id          . " : " .
                     $mod->nummer      . " : " .
                     $mod->bezeichnung . " : " .
                     (property_exists($mod, "moodle") &&
                      isset($mod->moodle) ? $mod->moodle->id : "not found") .
                     "\n";
            }

            // Stop immediately if the only matching module has been found
            if (gettype($m) == "string" &&
                $m === $mod->nummer)
            {
                if ($v) cli_problem("stop module processing");
                break 2;
            }
        }
    }

    return $mods;
}

function fetchStudents($mods, $param)
{
    $l  = $param["list"];
    $i  = $param["inform"];
    $v  = $param["verbose"];
    $s  = $param["students"];
    $c  = $param["auto-register"];
    $e  = $param["auto-enrol"];

    if (!$s) {
        // ensure unrequested information is not processed
        return;
    }

    if ($l || $v)
    {
        cli_heading("Participants");
    }

    if ($v) cli_problem("process " . count($mods) . " modules");

    foreach ($mods as $mod)
    {
        if ($l || $v)
        {
            cli_heading($mod->nummer . ": " . $mod->bezeichnung);
        }

        $members = loadEventoJson($mod->id, "modul");
        foreach ($members as $mb)
        {
            if (isset($i))
            {
                cli_problem("course member " . json_encode($mb));
            }

            if (!isset($mb->mail) ||
                empty($mb->mail))
            {
                continue;
            }

            $uc = "exists";
            if (!$user = checkUser($mb))
            {
                if ($c)
                {
                    $uc = "create";
                    $user = registerUser($mb, $v);
                }
                if (!$user)
                {
                    continue;
                }
            }

            $role = "student";
            if (property_exists($mb, "rolle"))
            {
                cli_problem("member has a role property");
                $role = "editingteacher";
            }

//            cli_problem("target role: " . $role);

            if (!checkEnrolment($mod, $user, $v, $role))
            {

                if ($e)
                {
                    // only enrol if enrolment is actually requested
                    enrolUser($mod, $user, $v, $role);
                }
            }

            if ($i || $l || $v)
            {
                echo $mb->uuid . " : " .
                     $mb->mail . " : " .
                     $uc       . " : " .
                     $role     . " : " .
                     (isset($user) ? $user->id : "no moodle user") . " : " .
                     (isset($user) &&
                      property_exists($user, 'isEnrolled') ? "enroled" : "not enroled") .
                     "\n";
            }
        }

        // TODO Verify that all enrolled participants are actually registered
    }
}

/** **********************************************************************
 * Get Command Line Arguments
 *  **********************************************************************  */

list($p, $u) = cli_get_params(array("verbose"       => false,
                                    "programs"      => false,
                                    "modules"       => false,
                                    "spattern"      => false,
                                    "students"      => false,
                                    "list"          => false,
                                    "auto-enrol"    => false,
                                    "auto-create"   => false,
                                    "auto-register" => false,
                                    "report"        => false,
                                    "inform"        => false,
                                    "help"          => false
                                   ),
                              array("c" => "auto-create",
                                    "C" => "auto-register",
                                    "e" => "auto-enrol",
                                    "i" => "inform",
                                    "l" => "list",
                                    "m" => "modules",
                                    "M" => "spattern",
                                    "p" => "programs",
                                    "r" => "report",
                                    "s" => "students",
                                    "v" => "verbose",
                                    "?" => "help"
                                   ));

if ($p["help"])
{
    echo $MSG_HELP;
    exit(0);
}

if ($p["verbose"])
{
    cli_problem("Show Extensive Messages");
}

if ($p["spattern"] && (gettype($p["spattern"]) !== "string" || empty($p["spattern"])))
{
    cli_error("-M requires a pattern string");
}

if ($p["inform"])
{
    $p["list"]          = true;
    $p["auto-enrol"]    = false;
    $p["auto-create"]   = false;
    $p["auto-register"] = false;
}

/** **********************************************************************
 * The actual Script Logic
 *  **********************************************************************  */

$progs    = fetchPrograms($p);
$modules  = fetchModules($progs, $p);
fetchStudents($modules, $p);

/** **********************************************************************
 * Report some stats if requested
 *  **********************************************************************  */

if ($p["report"])
{
    cli_heading("Statistics");
    echo "  Created $createCourse Courses\n";
    echo "  Created $createUser Users\n";
    echo "  Enrolled $enrolUser Users\n\n";
}

exit(0);

?>
