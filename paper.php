<?php
// paper.php -- HotCRP paper view and edit page
// HotCRP is Copyright (c) 2006-2007 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once('Code/header.inc');
require_once('Code/papertable.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$useRequest = false;
if (isset($_REQUEST["emailNote"]) && $_REQUEST["emailNote"] == "Optional explanation")
    unset($_REQUEST["emailNote"]);


// header
function confHeader() {
    global $paperId, $newPaper, $prow, $mode, $Conf;
    if ($paperId > 0)
	$title = "Paper #$paperId";
    else
	$title = ($newPaper ? "New Paper" : "Paper View");
    if ($mode == "edit")
	$title = "Edit $title";
    $Conf->header($title, "paper_" . $mode, actionBar($prow, $newPaper, $mode), false);
    $Conf->expandBody();
}

function errorMsgExit($msg) {
    global $Conf;
    confHeader();
    $Conf->errorMsgExit($msg);
}


// collect paper ID: either a number or "new"
$newPaper = false;
$paperId = -1;
if (isset($_REQUEST["paperId"]) && trim($_REQUEST["paperId"]) == "new")
    $newPaper = true;
else {
    maybeSearchPaperId("paper.php", $Me);
    $paperId = cvtint($_REQUEST["paperId"]);
}


// mode
$mode = "view";
if ($newPaper || (isset($_REQUEST["mode"]) && $_REQUEST["mode"] == "edit"))
    $mode = "edit";


// general error messages
if (isset($_REQUEST["post"]) && $_REQUEST["post"] && !count($_POST))
    $Conf->errorMsg("It looks like you tried to upload a gigantic file, larger than I can accept.  Any changes were lost.");


// date mode
$mainPreferences = false;
if ($mode == "view" && $Me->isPC && $Conf->timePCReviewPreferences())
    $mainPreferences = true;


// grab paper row
$prow = null;
function getProw($contactId) {
    global $prow, $paperId, $Conf, $Me, $mainPreferences;
    if (!($prow = $Conf->paperRow(array('paperId' => $paperId,
					'topics' => 1, 'tags' => 1, 'options' => 1,
					'reviewerPreference' => $mainPreferences),
				  $contactId, $whyNot))
	|| !$Me->canViewPaper($prow, $Conf, $whyNot))
	errorMsgExit(whyNotText($whyNot, "view"));
}
if (!$newPaper) {
    getProw($Me->contactId);
    // perfect mode: default to edit for non-submitted papers
    if ($mode == "view"
	&& (!isset($_REQUEST["mode"]) || $_REQUEST["mode"] != "view")
	&& $prow->conflictType == CONFLICT_AUTHOR
	&& $Conf->timeUpdatePaper($prow))
	$mode = "edit";
}


// set review preference action
if (isset($_REQUEST['revpref']) && $prow) {
    $ajax = defval($_REQUEST["ajax"], 0);
    if (!$Me->amAssistant()
	|| ($contactId = cvtint($_REQUEST["contactId"])) <= 0)
	$contactId = $Me->contactId;
    if (($v = cvtpref($_REQUEST['revpref'])) >= -1000000 && $v <= 1000000) {
	$while = "while saving review preference";
	$Conf->qe("lock tables PaperReviewPreference write", $while);
	$Conf->qe("delete from PaperReviewPreference where contactId=$contactId and paperId=$prow->paperId", $while);
	$result = $Conf->qe("insert into PaperReviewPreference (paperId, contactId, preference) values ($prow->paperId, $contactId, $v)", $while);
	$Conf->qe("unlock tables", $while);
	if ($result)
	    $Conf->confirmMsg("Review preference saved.");
	getProw($Me->contactId);
    } else {
	$Conf->errorMsg($ajax ? "Preferences must be small positive or negative integers." : "Preferences must be small integers.  0 means don't care; positive numbers mean you want to review a paper, negative numbers mean you don't.  The greater the absolute value, the stronger your feelings.");
	$PaperError['revpref'] = true;
    }
    if ($ajax)
	$Conf->ajaxExit(array("ok" => $OK && !defval($PaperError['revpref'])));
}


// withdraw and revive actions
if (isset($_REQUEST["withdraw"]) && !$newPaper) {
    if ($Me->canWithdrawPaper($prow, $Conf, $whyNot)) {
	$Conf->qe("update Paper set timeWithdrawn=" . time() . ", timeSubmitted=if(timeSubmitted>0,-100,0) where paperId=$paperId", "while withdrawing paper");
	$Conf->updatePapersubSetting(false);
	getProw($Me->contactId);

	// email contact authors themselves
	$m = "Paper #$paperId, \"$prow->title\", has been withdrawn from consideration for the $Conf->shortName conference.";
	if ($Me->amAssistant() && isset($_REQUEST["emailNote"]))
	    $m .= "\n\nA conference administrator provided the following reason for withdrawing the paper: " . trim($_REQUEST["emailNote"]);
	else if ($Me->amAssistant() && $prow->conflictType < CONFLICT_AUTHOR)
	    $m .= "\n\nA conference administrator withdrew the paper.";
	$m = wordwrap("$m\n\nContact the site administrator, $Conf->contactName <$Conf->contactEmail>, with any questions or concerns.\n\n- $Conf->shortName Conference Submissions\n");
	if (!$Me->amAssistant() || defval($_REQUEST["doemail"]) > 0)
	    $Conf->emailContactAuthors($prow, "Paper #$paperId withdrawn", $m);

	// email reviewers
	if ($prow->startedReviewCount > 0) {
	    $m = wordwrap("$Conf->shortName Paper #$paperId, which you reviewed or have been assigned to review, has been withdrawn from consideration for the conference.\n\n");
	    $n = "Authors can voluntarily withdraw a submission at any time, as can the chair.";
	    if ($Me->amAssistant() && isset($_REQUEST["emailNote"]))
		$n .= "  " . trim($_REQUEST["emailNote"]);
	    $m .= wordwrap($n . "\n\n")
		. wordWrapIndent($prow->title, "Title: ", 14) . "\n"
		. "  Paper site: $Conf->paperSite/review.php?paperId=$prow->paperId\n\n"
		. wordwrap("You are not expected to complete your review; in fact the conference system will not allow it unless the chair revives the paper.\n\nContact the site administrator, $Conf->contactName <$Conf->contactEmail>, with any questions or concerns.\n\n- $Conf->shortName Conference Submissions\n");
	    $Conf->emailReviewersChair($prow, "Paper #$paperId withdrawn", $m);
	}
	
	$Conf->log("Withdrew", $Me, $paperId);
    } else
	$Conf->errorMsg(whyNotText($whyNot, "withdraw"));
}
if (isset($_REQUEST["revive"]) && !$newPaper) {
    if ($Me->canRevivePaper($prow, $Conf, $whyNot)) {
	$Conf->qe("update Paper set timeWithdrawn=0, timeSubmitted=if(timeSubmitted=-100," . time() . ",0) where paperId=$paperId", "while reviving paper");
	$Conf->updatePapersubSetting(true);
	getProw($Me->contactId);
	$Conf->log("Revived", $Me, $paperId);
	$_REQUEST["update"] = true;
    } else
	$Conf->errorMsg(whyNotText($whyNot, "revive"));
}


// update paper action
$PaperError = array();

function setRequestFromPaper($prow) {
    foreach (array("title", "abstract", "authorInformation", "collaborators") as $x)
	if (!isset($_REQUEST[$x]))
	    $_REQUEST[$x] = $prow->$x;
}

function requestSameAsPaper($prow) {
    global $Conf;
    foreach (array("title", "abstract", "authorInformation", "collaborators") as $x)
	if ($_REQUEST[$x] != $prow->$x)
	    return false;
    if (fileUploaded($_FILES['paperUpload'], $Conf))
	return false;
    $result = $Conf->q("select TopicArea.topicId, PaperTopic.paperId from TopicArea left join PaperTopic on PaperTopic.paperId=$prow->paperId and PaperTopic.topicId=TopicArea.topicId");
    while (($row = edb_row($result))) {
	$got = isset($_REQUEST["top$row[0]"]) && cvtint($_REQUEST["top$row[0]"]) > 0;
	if (($row[1] > 0) != $got)
	    return false;
    }
    if ($Conf->setting("paperOption")) {
	$result = $Conf->q("select OptionType.optionId, PaperOption.paperId from OptionType left join PaperOption on PaperOption.paperId=$prow->paperId and PaperOption.optionId=OptionType.optionId");
	while (($row = edb_row($result))) {
	    $got = isset($_REQUEST["opt$row[0]"]) && cvtint($_REQUEST["opt$row[0]"]) > 0;
	    if (($row[1] > 0) != $got)
		return false;
	}
    }
    return true;
}

function uploadPaper($isSubmitFinal) {
    global $prow, $Conf, $Me;
    $result = $Conf->storePaper('paperUpload', $prow, $isSubmitFinal,
				$Me->amAssistant() && defval($_REQUEST["override"]));
    if ($result == 0 || PEAR::isError($result)) {
	$Conf->errorMsg("There was an error while trying to update your paper.  Please try again.");
	return false;
    }
    return true;
}

function updatePaper($Me, $isSubmit, $isSubmitFinal) {
    global $paperId, $newPaper, $PaperError, $Conf, $prow;
    $contactId = $Me->contactId;
    if ($isSubmitFinal)
	$isSubmit = false;

    // XXX lock tables

    // check that all required information has been entered
    array_ensure($_REQUEST, "", "title", "abstract", "authorInformation", "collaborators");
    $q = "";
    foreach (array("title", "abstract", "authorInformation", "collaborators") as $x) {
	if (trim($_REQUEST[$x]) == "") {
	    if ($x != "collaborators"
		|| ($Conf->setting("sub_collab") && $isSubmit))
		$PaperError[$x] = 1;
	}
	if ($x == "title")
	    $_REQUEST[$x] = simplifyWhitespace($_REQUEST[$x]);
	$q .= "$x='" . sqlqtrim($_REQUEST[$x]) . "', ";
    }

    // any missing fields?
    if (count($PaperError) > 0) {
	$Conf->errorMsg("One or more required fields were left blank.  Fill in the highlighted fields and try again." . (isset($PaperError["collaborators"]) ? "  If none of the authors have recent collaborators, just enter \"None\" in the Collaborators field." : ""));
	$isSubmit = false;
    }

    // defined contact ID
    if ($newPaper && (isset($_REQUEST["contact_email"]) || isset($_REQUEST["contact_name"])) && $Me->amAssistant())
	if (!($contactId = $Conf->getContactId($_REQUEST["contact_email"], "contact_"))) {
	    $Conf->errorMsg("You must supply a valid email address for the contact author.");
	    $PaperError["contactAuthor"] = 1;
	    return false;
	}

    // blind?
    if ($isSubmitFinal)
	/* do nothing */;
    else if ($Conf->blindSubmission() > 1
	     || ($Conf->blindSubmission() == 1 && defval($_REQUEST['blind'])))
	$q .= "blind=1, ";
    else
	$q .= "blind=0, ";
    
    // update Paper table
    if ($newPaper)
	$q .= "paperStorageId=1";
    else
	$q = substr($q, 0, -2) . " where paperId=$paperId and timeWithdrawn<=0";

    $result = $Conf->qe(($newPaper ? "insert into" : "update") . " Paper set $q", "while updating paper information");
    if (!$result)
	return false;

    // fetch paper ID
    if ($newPaper) {
	$result = $Conf->qe("select last_insert_id()", "while updating paper information");
	if (edb_nrows($result) == 0)
	    return false;
	$row = edb_row($result);
	$paperId = $row[0];

	$result = $Conf->qe("insert into PaperConflict (paperId, contactId, conflictType) values ($paperId, $contactId, " . CONFLICT_AUTHOR . ")", "while updating paper information");
	if (!$result)
	    return false;
    }

    // update topics table
    if (!$isSubmitFinal) {
	if (!$Conf->qe("delete from PaperTopic where paperId=$paperId", "while updating paper topics"))
	    return false;
	$q = "";
	foreach ($_REQUEST as $key => $value)
	    if ($key[0] == 't' && $key[1] == 'o' && $key[2] == 'p'
		&& ($id = cvtint(substr($key, 3))) > 0 && $value > 0)
		$q .= "($paperId, $id), ";
	if ($q && !$Conf->qe("insert into PaperTopic (paperId, topicId) values " . substr($q, 0, strlen($q) - 2), "while updating paper topics"))
	    return false;
    }

    // update options table
    if (!$isSubmitFinal && $Conf->setting("paperOption")) {
	if (!$Conf->qe("delete from PaperOption where paperId=$paperId", "while updating paper options"))
	    return false;
	$q = "";
	foreach (paperOptions() as $opt)
	    if (defval($_REQUEST["opt$opt->optionId"]) > 0)
		$q .= "($paperId, $opt->optionId, 1), ";
	if ($q && !$Conf->qe("insert into PaperOption (paperId, optionId, value) values " . substr($q, 0, strlen($q) - 2), "while updating paper options"))
	    return false;
    }

    // update PC conflicts if appropriate
    if ($Conf->setting("sub_pcconf")) {
	if (!$Conf->qe("delete from PaperConflict where paperId=$paperId and conflictType=" . CONFLICT_AUTHORMARK, "while updating conflicts"))
	    return false;
	$q = "";
	foreach ($_REQUEST as $key => $value)
	    if ($key[0] == 'p' && $key[1] == 'c' && $key[2] == 'c'
		&& ($id = cvtint(substr($key, 3))) > 0 && $value > 0)
		$q .= ",$id";
	if ($q && !$Conf->qe("insert into PaperConflict (paperId, contactId, conflictType)
	select $paperId, PCMember.contactId, " . CONFLICT_AUTHORMARK . "
	from PCMember left join PaperConflict on (PCMember.contactId=PaperConflict.contactId and PaperConflict.paperId=$paperId)
	where conflictType is null and PCMember.contactId in (" . substr($q, 1) . ")",
			     "while updating conflicts"))
	    return false;
    }
    
    // upload paper if appropriate
    if (fileUploaded($_FILES['paperUpload'], $Conf)) {
	if ($newPaper)
	    getProw($contactId);
	if (!uploadPaper($isSubmitFinal))
	    return false;
    }

    // submit paper if appropriate
    if ($isSubmitFinal || $isSubmit) {
	getProw($contactId);
	if (($isSubmitFinal ? $prow->finalPaperStorageId : $prow->paperStorageId) <= 1) {
	    $PaperError["paper"] = 1;
	    return $Conf->errorMsg(whyNotText("notUploaded", ($isSubmitFinal ? "submit a final copy for" : "submit"), $paperId));
	}
	$result = $Conf->qe("update Paper set " . ($isSubmitFinal ? "timeFinalSubmitted" : "timeSubmitted") . "=" . time() . " where paperId=$paperId",
			    ($isSubmitFinal ? "while submitting final copy for paper" : "while submitting paper"));
	if (!$result)
	    return false;
	$Conf->updatePapersubSetting(true);
    } else {
	$result = $Conf->qe("update Paper set timeSubmitted=0 where paperId=$paperId", "while unsubmitting paper");
	if (!$result)
	    return false;
    }
    
    // confirmation message
    getProw($contactId);
    if ($isSubmitFinal) {
	$what = "Submitted final copy for";
	$subject = "Updated Paper #$paperId final copy";
    } else {
	$what = ($isSubmit ? "Submitted" : ($newPaper ? "Registered" : "Updated"));
	$subject = $what . " Paper #$paperId";
    }
    if (($titleWords = titleWords($prow->title)))
	$subject .= " \"$titleWords\"";
    $Conf->confirmMsg("$what paper #$paperId.");
    

    // send paper email
    $m = wordwrap("This mail confirms the "
	. ($isSubmitFinal ? "submission of a final copy for"
	   : ($isSubmit ? "submission of"
	      : ($newPaper ? "creation of" : "update of")))
	. " paper #$paperId at the $Conf->shortName conference submission site.") . "\n\n"
	. wordWrapIndent(trim($prow->title), "Title: ") . "\n"
	. wordWrapIndent(trim($prow->authorInformation), "Authors: ") . "\n"
	. "      Paper site: $Conf->paperSite/paper.php?paperId=$paperId\n\n";
    if ($isSubmitFinal) {
	$deadline = $Conf->printableTimeSetting("final_done");
	$mx = ($deadline != "N/A" ? "You have until $deadline to make further changes." : "");
    } else {
	if ($isSubmit || $prow->timeSubmitted > 0)
	    $mx = "You will receive email when reviews are available.";
	else if ($Conf->setting("sub_freeze") > 0)
	    $mx = "You have not yet submitted a final version of this paper.";
	else
	    $mx = "This version of the paper is marked as not ready for review.";
	$deadline = $Conf->printableTimeSetting("sub_update");
	if ($deadline != "N/A" && ($prow->timeSubmitted <= 0 || $Conf->setting("sub_freeze") <= 0))
	    $mx .= "  You have until $deadline to update the paper further.";
	$deadline = $Conf->printableTimeSetting("sub_sub");
	if ($deadline != "N/A" && $prow->timeSubmitted <= 0)
	    $mx .= "  If you do not submit the paper by $deadline, it will not be considered for the conference.";
    }
    if ($Me->amAssistant() && isset($_REQUEST["emailNote"]))
	$mx .= "\n\nA conference administrator provided the following reason for this update: " . $_REQUEST["emailNote"];
    else if ($Me->amAssistant() && $prow->conflictType < CONFLICT_AUTHOR)
	$mx .= "\n\nA conference administrator performed this update.";
    $mx .= ($mx == "" ? "" : "\n\n");
    $m .= wordwrap($mx . "Contact the site administrator, $Conf->contactName <$Conf->contactEmail>, with any questions or concerns.

- $Conf->shortName Conference Submissions\n");

    // send email to all contact authors
    if (!$Me->amAssistant() || defval($_REQUEST["doemail"]) > 0)
	$Conf->emailContactAuthors($prow, $subject, $m);
    
    $Conf->log($what, $Me, $paperId);
    return true;
}

if (isset($_REQUEST["update"]) || isset($_REQUEST["submitfinal"])) {
    // get missing parts of request
    if (!$newPaper)
	setRequestFromPaper($prow);

    // check deadlines
    if ($newPaper)
	// we know that canStartPaper implies canFinalizePaper
	$ok = $Me->canStartPaper($Conf, $whyNot);
    else if (isset($_REQUEST["submitfinal"]))
	$ok = $Me->canSubmitFinalPaper($prow, $Conf, $whyNot);
    else {
	$ok = $Me->canUpdatePaper($prow, $Conf, $whyNot);
	if (!$ok && isset($_REQUEST["submit"]) && requestSameAsPaper($prow))
	    $ok = $Me->canFinalizePaper($prow, $Conf, $whyNot);
    }

    // actually update
    if (!$ok) {
	if (isset($_REQUEST["submitfinal"]))
	    $action = "submit final copy for";
	else
	    $action = ($newPaper ? "register" : "update");
	$Conf->errorMsg(whyNotText($whyNot, $action));
    } else if (updatePaper($Me, isset($_REQUEST["submit"]), isset($_REQUEST["submitfinal"]))) {
	if ($newPaper)
	    $Conf->go("paper.php?paperId=$paperId&mode=edit");
    }

    // use request?
    $useRequest = ($ok || $Me->amAssistant());
}


// delete action
if (isset($_REQUEST['delete'])) {
    if ($newPaper)
	$Conf->confirmMsg("Paper deleted.");
    else if (!$Me->amAssistant())
	$Conf->errorMsg("Only the program chairs can permanently delete papers.  Authors can withdraw papers, which is effectively the same.");
    else {
	// mail first, before contact info goes away
	$m = "Your $Conf->shortName paper submission #$paperId, \"$prow->title\", has been removed from the conference database by the program chairs.  This is usually done to remove duplicate papers.";
	if ($Me->amAssistant() && isset($_REQUEST["emailNote"]))
	    $m .= "\n\nA conference administrator provided the following reason for deleting the paper: " . $_REQUEST["emailNote"];
	$m = wordwrap("$m\n\nContact the site administrator, $Conf->contactName <$Conf->contactEmail>, with any questions or concerns.\n\n- $Conf->shortName Conference Submissions\n");
	if (!$Me->amAssistant() || defval($_REQUEST["doemail"]) > 0)
	    $Conf->emailContactAuthors($prow, "Paper #$paperId deleted", $m);
	// XXX email self?

	$error = false;
	$tables = array('Paper', 'PaperStorage', 'PaperComment', 'PaperConflict', 'PaperReview', 'PaperReviewArchive', 'PaperReviewPreference', 'PaperTopic');
	if ($Conf->setting("allowPaperOption"))
	    $tables[] = 'PaperOption';
	foreach ($tables as $table) {
	    $result = $Conf->qe("delete from $table where paperId=$paperId", "while deleting paper");
	    $error |= ($result == false);
	}
	if (!$error) {
	    $Conf->confirmMsg("Paper #$paperId deleted.");
	    $Conf->updatePapersubSetting(false);
	    $Conf->log("Deleted", $Me, $paperId);
	}
	
	$prow = null;
	errorMsgExit("");
    }
}


// messages for the author
function deadlineSettingIs($dname, $conf) {
    $deadline = $conf->printableTimeSetting($dname);
    if ($deadline == "N/A")
	return "";
    else if (time() < $conf->setting($dname))
	return "  The deadline is $deadline.";
    else
	return "  The deadline was $deadline.";
}

$override = ($Me->amAssistant() ? "  As PC Chair, you can override this deadline using the \"Override deadlines\" checkbox." : "");
if ($mode != "edit")
    /* do nothing */;
else if ($newPaper) {
    $timeStart = $Conf->timeStartPaper();
    $startDeadline = deadlineSettingIs("sub_reg", $Conf);
    if (!$timeStart) {
	if ($Conf->setting("sub_open") <= 0)
	    $msg = "You can't register new papers because the conference site has not been opened for submissions.$override";
	else
	    $msg = "You can't register new papers since the <a href='deadlines.php'>deadline</a> has passed.$startDeadline$override";
	if (!$Me->amAssistant())
	    errorMsgExit($msg);
	$Conf->infoMsg($msg);
    }
} else if ($prow->conflictType == CONFLICT_AUTHOR
	   && ($Conf->timeUpdatePaper($prow) || $prow->timeSubmitted <= 0)) {
    $timeUpdate = $Conf->timeUpdatePaper($prow);
    $updateDeadline = deadlineSettingIs("sub_update", $Conf);
    $timeSubmit = $Conf->timeFinalizePaper($prow);
    $submitDeadline = deadlineSettingIs("sub_sub", $Conf); 
    if ($timeUpdate && $prow->timeWithdrawn > 0)
	$Conf->infoMsg("Your paper has been withdrawn, but you can still revive it.$updateDeadline");
    else if ($timeUpdate) {
	if ($prow->timeSubmitted <= 0) {
	    if ($Conf->setting('sub_freeze'))
		$Conf->infoMsg("You must submit a final version of your paper before it can be reviewed.$updateDeadline");
	    else
		$Conf->infoMsg("The current version of the paper is marked as not ready for review.  If you don't submit a reviewable version of the paper, it will not be considered.$updateDeadline"); 
	} else
	    $Conf->infoMsg("Your paper is ready for review and will be considered for the conference.  However, you still have time to make changes before submissions are frozen.$updateDeadline");
    } else if ($prow->timeWithdrawn <= 0 && $timeSubmit)
	$Conf->infoMsg("You cannot make any changes as the <a href='deadlines.php'>deadline</a> has passed, but the current version can still be submitted.  Only submitted papers will be reviewed.$submitDeadline$override");
    else if ($prow->timeWithdrawn <= 0)
	$Conf->infoMsg("The <a href='deadlines.php'>deadline</a> for submitting this paper has passed.  The paper will not be reviewed.$submitDeadline$override");
} else if ($prow->conflictType == CONFLICT_AUTHOR && $prow->outcome > 0 && $Conf->timeSubmitFinalPaper()) {
    $updateDeadline = deadlineSettingIs("final_done", $Conf);
    $Conf->infoMsg("Congratulations!  This paper was accepted.  Submit a final copy for your paper here.$updateDeadline  You may also withdraw the paper (in extraordinary circumstances) or add contact authors, allowing others to view reviews and make changes.");
} else if ($prow->conflictType == CONFLICT_AUTHOR) {
    $override2 = ($Me->amAssistant() ? "  However, as PC Chair, you can update the paper anyway by selecting \"Override deadlines\"." : "");
    $Conf->infoMsg("This paper is under review and can no longer be changed.  You can still withdraw the paper or add contact authors, allowing others to view reviews as they become available.$override2");
} else if (!$Me->amAssistant())
    errorMsgExit("You can't edit paper #$paperId since you aren't one of its contact authors.");
else
    $Conf->infoMsg("You aren't a contact author for this paper, but can still make changes as PC Chair.");


// page header
confHeader();


// begin table
$finalEditMode = false;
if ($mode == "edit") {
    echo "<form method='post' action=\"paper.php?paperId=",
	($newPaper ? "new" : $paperId),
	"&amp;post=1&amp;mode=edit\" enctype='multipart/form-data'>";
    $editable = $newPaper || ($prow->timeWithdrawn <= 0
			      && ($Conf->timeUpdatePaper($prow) || $Me->amAssistant()));
    if ($prow && $prow->outcome > 0 && ($Conf->timeSubmitFinalPaper() || $Me->amAssistant()))
	$editable = $finalEditMode = true;
} else
    $editable = false;


// prepare paper table
$canViewAuthors = $Me->canViewAuthors($prow, $Conf, false);
if ($mode == "edit" && $Me->amAssistant())
    $canViewAuthors = true;

if ($editable)
    $spacer = "<tr><td class='caption'></td><td class='entry'><hr class='smgap' /></td></tr>\n";
else
    $spacer = "";

$paperTable = new PaperTable($editable, $editable && $useRequest, false, !$canViewAuthors && $Me->amAssistant(), "paper");

$paperTable->echoDivEnter();
echo "<table class='paper", ($mode == "edit" ? " editpaper" : ""), "'>\n\n";


// title
if (!$newPaper) {
    echo "<tr class='id'>\n  <td class='caption'><h2>#$paperId</h2></td>\n";
    echo "  <td class='entry' colspan='2'><h2>";
    $paperTable->echoTitle($prow);
    echo "</h2></td>\n</tr>\n\n";
}


// Editable title
if ($editable)
    $paperTable->echoTitleRow($prow);


// Current status
$flags = PaperTable::STATUS_DATE;
if ($finalEditMode)
    $flags += PaperTable::FINALCOPY;
if ($editable && $newPaper)
    $flags += PaperTable::OPTIONAL;
if (!$editable)
    $flags += PaperTable::STATUS_CONFLICTINFO | PaperTable::STATUS_REVIEWERINFO;
if (!$newPaper)
    $paperTable->echoPaperRow($prow, $flags);


// Upload paper
if ($editable)
    $paperTable->echoUploadRow($prow,
		($finalEditMode ? PaperTable::FINALCOPY : 0)
		+ ($newPaper ? PaperTable::OPTIONAL : 0)
		+ (!$prow || $prow->size == 0 ? PaperTable::ENABLESUBMIT : 0));

echo $spacer;


// Authors
if ($newPaper || $canViewAuthors || $Me->amAssistant())
    $paperTable->echoAuthorInformation($prow);


// Contact authors
if ($newPaper)
    $paperTable->echoNewContactAuthor($Me->amAssistant());
else if ($canViewAuthors || $Me->amAssistant())
    $paperTable->echoContactAuthor($prow, $mode == "edit");


// Anonymity
if (($newPaper || $mode == "edit") && $Conf->blindSubmission() == 1 && !$finalEditMode) {
    echo "<tr class='pt_blind'>\n  <td class='caption'>Anonymity</td>\n";
    $blind = ($useRequest ? isset($_REQUEST['blind']) : (!$prow || $prow->blind));
    if ($paperTable->editable) {
	echo "  <td class='entry'>";
	echo "<div class='hint'>", htmlspecialchars($Conf->shortName), " allows either anonymous or named submission.  Check this box to submit the paper anonymously (reviewers won't be shown the author list).  Make sure you also remove your name from the paper itself!</div>\n";
	echo "<input type='checkbox' name='blind' value='1'";
	if ($blind)
	    echo " checked='checked'";
	echo " />&nbsp;Anonymous submission</td>\n";
    } else
	echo "  <td class='entry'>", ($blind ? "Anonymous submission" : "Non-anonymous submission"), "</td>\n";
    echo "</tr>\n";
}

echo $spacer;


// Abstract
$paperTable->echoAbstractRow($prow);

echo $spacer;


// Topics
if (!$finalEditMode)
    $paperTable->echoTopics($prow);


// Options
$paperTable->echoOptions($prow, $Me->amAssistant());


// Tags
if ($Me->isPC && $prow && $prow->conflictType <= 0 && !$editable)
    $paperTable->echoTags($prow);


// Potential conflicts
if ($paperTable->editable || $Me->amAssistant())
    $paperTable->echoPCConflicts($prow);
if (($newPaper || $canViewAuthors || $Me->amAssistant()) && !$finalEditMode)
    $paperTable->echoCollaborators($prow);


// Review preference
if ($mode != "edit" && $mainPreferences && $prow->conflictType <= 0) {
    $x = (isset($prow->reviewerPreference) ? htmlspecialchars($prow->reviewerPreference) : "0");
    echo "<tr class='pt_preferences'>
  <td class='caption";
    if (isset($PaperError['revpref']))
	echo " error";
    echo "'>Review preference</td>
  <td class='entry'><form id='revpref' name='revpref' action=\"", $ConfSiteBase, "paper.php?paperId=", $prow->paperId, "&amp;post=1\" method='post' enctype='multipart/form-data' onsubmit='return Miniajax.submit(\"revpref\", {setrevpref:1})'>
    <input class='textlite' type='text' size='4' name='revpref' value=\"$x\" onfocus=\"tempText(this, '0', 1)\" onblur=\"tempText(this, '0', 0)\" onchange='highlightUpdate(\"revpref\")' tabindex='1' />&nbsp;
    <input class='button_small' type='submit' value='Save preference' tabindex='1' />
    <span id='revprefresult' style='padding-left:1em'></span>
  </form></td>
</tr>\n\n";
}


// Outcome
// if ($mode != "edit" && $Me->canSetOutcome($prow))
//     $paperTable->echoOutcomeSelector($prow);


// Submit button
if ($mode == "edit") {
    echo $spacer;
    echo "<tr class='pt_edit'>
  <td class='caption'></td>
  <td class='entry' colspan='2'><table class='pt_buttons'>\n";
    $buttons = array();
    if ($newPaper)
	$buttons[] = "<input class='button' type='submit' name='update' value='Register paper' />";
    else if ($prow->timeWithdrawn > 0 && ($Conf->timeFinalizePaper($prow) || $Me->amAssistant()))
	$buttons[] = "<input class='button' type='submit' name='revive' value='Revive paper' />";
    else if ($prow->timeWithdrawn > 0)
	$buttons[] = "The paper has been withdrawn, and the <a href='deadlines.php'>deadline</a> for reviving it has passed.";
    else {
	if ($prow->outcome > 0 && ($Conf->timeSubmitFinalPaper() || $Me->amAssistant()))
	    $buttons[] = array("<input class='button' type='submit' name='submitfinal' value='Submit final copy' />", "");
	if ($Conf->timeUpdatePaper($prow))
	    $buttons[] = array("<input class='button' type='submit' name='update' value='Update paper' />", "");
	else if ($Me->amAssistant())
	    $buttons[] = array("<input class='button' type='submit' name='update' value='Update paper' />", "(PC chair only)");
	if ($prow->timeSubmitted <= 0)
	    $buttons[] = "<input class='button' type='submit' name='withdraw' value='Withdraw paper' />";
	else
	    $buttons[] = "<div id='foldw' class='foldc' style='position: relative'><button type='button' onclick=\"fold('w', 0)\">Withdraw paper</button><div class='popupdialog extension'><p>Are you sure you want to withdraw this paper from consideration and/or publication?  Only the PC chair can undo this step.</p><input class='button' type='submit' name='withdraw' value='Withdraw paper' /> <button type='button' onclick=\"fold('w', 1)\">Cancel</button></div></div>";
    }
    if ($Me->amAssistant() && !$newPaper)
	$buttons[] = array("<div id='folddel' class='foldc' style='position: relative'><button type='button' onclick=\"fold('del', 0)\">Delete paper</button><div class='popupdialog extension'><p>Be careful: This will permanently delete all information about this paper from the database and <strong>cannot be undone</strong>.</p><input class='button' type='submit' name='delete' value='Delete paper' /> <button type='button' onclick=\"fold('del', 1)\">Cancel</button></div></div>", "(PC chair only)");
    echo "    <tr>\n";
    foreach ($buttons as $b) {
	$x = (is_array($b) ? $b[0] : $b);
	echo "      <td class='ptb_button'>", $x, "</td>\n";
    }
    echo "    </tr>\n    <tr>\n";
    foreach ($buttons as $b) {
	$x = (is_array($b) ? $b[1] : "");
	echo "      <td class='ptb_explain'>", $x, "</td>\n";
    }
    echo "    </tr>\n  </table></td>\n</tr>\n\n";
    if ($Me->amAssistant()) {
	echo "<tr>
  <td class='caption'></td>
  <td class='entry' colspan='2'><table>\n";
	//if ($prow && (($prow->conflictType < CONFLICT_AUTHOR && $prow->timeSubmitted <= 0)
	//	      || $prow->timeSubmitted > 0))
	//   echo "    <span class='sep'></span>\n";
	// echo "<input type='checkbox' name='emailUpdate' value='1'",
	//	($prow->timeSubmitted > 0 ? "" : " checked='checked'"),
	//	" />&nbsp;Email&nbsp;authors\n",
	//	"    <span class='sep'></span>\n";
	echo "      <tr><td><input type='checkbox' name='doemail' value='1' checked='checked' />&nbsp;Email authors, including:&nbsp; ";
	echo "<input type='text' class='textlite' name='emailNote' size='30' value='Optional explanation' onfocus=\"tempText(this, 'Optional explanation', 1)\" onblur=\"tempText(this, 'Optional explanation', 0)\" /></tr></td>\n";
	echo "      <tr><td><input type='checkbox' name='override' value='1' />&nbsp;Override deadlines</td></tr>\n";
	echo "  </table></td>\n</tr>\n\n";
    }
}


// End paper view
echo "<tr class='last'><td class='caption'></td><td class='entry' colspan='2'></td></tr>
</table>";
$paperTable->echoDivExit();

if ($mode == "edit")
    echo "</form>\n";
echo "<div class='clear'></div>\n\n";


$Conf->footer();
