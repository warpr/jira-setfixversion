<?php

$verbose = True;

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
        if (preg_match ("/20[0-9][0-9]-[0-9][0-9]-[0-9][0-9]/", $val->name, $matches))
        {
            $candidates[$matches[0]] = $val;
        }
    }

    $today = strftime ("%Y-%m-%d");
    $dates = array_keys ($candidates);
    array_push ($dates, $today);
    sort ($dates);

    reset ($dates);
    while ($val = next ($dates))
    {
        if (strcmp ($val, $today) > 0)
        {
            return $candidates[$val];
        }

        next ($dates);
    }

    return NULL;
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

function set_fix_version ($issue, $next_version) {
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

    list ($client, $login) = soap_connect ();
    $actionParam = new RemoteFieldValue ('fixVersions', array('id' => $next_version->id));
    $new_issue = $client->updateIssue ($login, $issue->key, array ($actionParam));

    echo $issue_url." ($fixVersion -> ".$new_issue->fixVersions[0]->name.")\n";
}

$next_version = next_version ("MBS");
if ($verbose)
{
    echo "Next version is ".$next_version->name."\n";
}

$issues = fetch_issues ("MBS");

foreach ($issues as $key => $val)
{
    set_fix_version ($issues[$key], $next_version);
}
