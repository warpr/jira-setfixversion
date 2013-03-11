#!/usr/bin/php
<?php

$verbose = FALSE;

class RemoteFieldValue {
    var $id;
    var $values = array();

    function __construct($idIn, $valuesIn) {
        $this->id = $idIn;
        $this->values = $valuesIn;
    }
}

function soap_connect ()
{
    $client = new SoapClient (dirname (__FILE__)."/jira.wsdl");
    list ($username, $password) = explode (", ", file_get_contents (dirname (__FILE__)."/.jira-password"));

    $login = $client->login (trim ($username), trim ($password));

    return array ($client, $login);
}

function fetch_project ($name)
{
    list ($client, $login) = soap_connect ();

    return $client->getProjectByKey ($login, $name);
}

function next_version ($name)
{
    list ($client, $login) = soap_connect ();
    $all_versions = $client->getVersions ($login, $name);

    $candidates = array ();
    foreach ($all_versions as $key => $val)
    {
        if (!$val->released && preg_match ("/20[0-9][0-9]-[0-9][0-9]-[0-9][0-9]/", $val->name, $matches))
        {
            $candidates[$matches[0]] = $val;
        }
    }

    if (empty ($candidates))
        return NULL;

    $dates = array_keys ($candidates);
    sort ($dates);

    return $candidates[$dates[0]];
}

function fetch_issues ($project)
{
    list ($client, $login) = soap_connect ();
    $issues_from_filter = $client->getIssuesFromFilter ($login, 10149);

    foreach ($issues_from_filter as $issue)
    {
        if ($issue->project == $project)
        {
            $issues[$issue->key] = $issue;
        }
    }
    ksort ($issues);

    return $issues;
}

function set_fix_version ($dryrun, $issue, $next_version) {
    global $verbose;

    $fixVersion = "";
    if (!empty ($issue->fixVersions))
    {
        $fixVersion = $issue->fixVersions[0]->name;
    }

    $issue_url = "http://tickets.musicbrainz.org/browse/".$issue->key;
    if ($fixVersion == $next_version->name)
    {
        if ($verbose)
        {
            echo $issue_url." ($fixVersion)\n";
        }
        return;
    }

    if (!$dryrun)
    {
        list ($client, $login) = soap_connect ();
        $actionParam = new RemoteFieldValue ('fixVersions', array('id' => $next_version->id));
        $new_issue = $client->updateIssue ($login, $issue->key, array ($actionParam));
        echo $issue_url." ($fixVersion -> ".$new_issue->fixVersions[0]->name.")\n";
    }
    else
    {
        echo $issue_url." ($fixVersion -> ".$next_version->name.")\n";
    }
}

function main ($dryrun) {
    global $verbose;

    $next_version = next_version ("MBS");
    if (empty ($next_version))
    {
        echo "Could not determine next version.\n";
    }

    if ($verbose)
    {
        echo "Next version is ".$next_version->name."\n";
    }

    $issues = fetch_issues ("MBS");

    foreach ($issues as $key => $val)
    {
        set_fix_version ($dryrun, $issues[$key], $next_version);
    }
}

function help ($argv) {
    echo "Usage: ".$argv[0]." [--fix]\n";
    echo "\n";
    echo "\t--fix\tFix all the issues (default is just a dry run)\n";
    echo "\n";
}

$dryrun = True;
if ($argc > 1 && $argv[1] == "--fix")
{
    $dryrun = False;
}

if ($argc > 1 && in_array($argv[1], array('--help', '-help', '-h', '-?'))) {
    help ($argv);
} else {
    main ($dryrun);
}

