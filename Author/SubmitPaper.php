<?php 
include('../Code/confHeader.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid("../");

$can_start = $Conf->timeStartPaper();
if (!$can_start && !$Me->amAssistant())
    $Me->goAlert("../", "The <a href='All/ImportantDates.php'>deadline</a> for starting new papers has passed.");

function pt_caption_class($what) {
    global $PaperError;
    if (isset($PaperError[$what]))
	return "pt_caption error";
    else
	return "pt_caption";
}

function pt_data_html($what) {
    if (isset($_REQUEST[$what]))
	return htmlspecialchars($_REQUEST[$what]);
    else
	return "";
}

if (isset($_REQUEST['submit'])) {
    $anyErrors = 0;
    if (!$can_start && !isset($_REQUEST['override'])) {
	$Error = "The <a href='../All/ImportantDates.php'>deadline</a> for starting new papers has passed.  Select the \"Override deadlines\" checkbox and try again if you really want to override this deadline.";
	$anyErrors = 1;
    }
    foreach (array('title', 'abstract', 'authorInformation') as $what) {
	if (!isset($_REQUEST[$what]) || $_REQUEST[$what] == "")
	    $PaperError[$what] = $anyErrors = 1;
    }
    if (!$anyErrors) {
	$query = "insert into Paper set title='" . sqlq($_REQUEST['title'])
	    . "', abstract='" . sqlq_cleannl($_REQUEST['abstract'])
	    . "', authorInformation='" . sqlq_cleannl($_REQUEST['authorInformation'])
	    . "', contactId=" . $Me->contactId
	    . ", paperStorageId=1";
	if (isset($_REQUEST["collaborators"]))
	    $query .= ", collaborators='" . sqlq_cleannl($_REQUEST["collaborators"]) . "'";
	$result = $Conf->q($query);
	if (DB::isError($result))
	    $Error = $Conf->dbErrorText($result, "while adding your paper to the database");
	else {
	    $result = $Conf->q("select last_insert_id()");
	    if (DB::isError($result))
		$Error = $Conf->dbErrorText($result, "while extracting your new paper's ID from the database");
	}
	if (!isset($Error)) {
	    $row = $result->fetchRow();
	    $paperId = $row[0];
	    $result = $Conf->q("insert into PaperConflict set contactId=$Me->contactId, paperId=$paperId, author=1");
	    if (DB::isError($result))
		$Error = $Conf->dbErrorText($result, "while associating you with your new paper #$paperId");
	}
	if (!isset($Error)) {
	    $msg = "A record of your paper has been created.";
	    if (!fileUploaded($_FILES["uploadedFile"]))
		$msg .= "  You still need to upload the actual paper.";
	    $Conf->confirmMsg($msg);

	    // now set topics
	    foreach ($_REQUEST as $key => $value)
		if ($key[0] == 't' && $key[1] == 'o' && $key[2] == 'p'
		    && ($id = (int) substr($key, 3)) > 0) {
		    $result = $Conf->qe("insert into PaperTopic set paperId=$paperId, topicId=$id", "while updating paper topics");
		    if (DB::isError($result))
			break;
		}
	    
	    $Conf->storePaper("uploadedFile", $paperId);
	    $Me->go("ManagePaper.php?paperId=$paperId");
	}
    }
 }

$Conf->header("Start New Paper");
if (isset($PaperError))
    $Conf->errorMsg("One or more required fields were left blank.  Fill in those fields and try again.");
if (isset($Error))
    $Conf->errorMsg($Error);
else if (!$can_start)
    $Conf->warnMsg("The <a href='../All/ImportantDates.php'>deadline</a> for starting new papers has passed, but you can still submit a new paper in your capacity as PC Chair or PC Chair's Assistant.");
?>

<form method='post' action='SubmitPaper.php' enctype='multipart/form-data'>
<p>Enter the following information. We will use your contact information
as the contact information for this paper.</p>

<table class='aumanage'>
<tr>
  <td class='<?php echo pt_caption_class("title") ?>'>Title:</td>
  <td class='pt_entry'><input class='textlite' type='text' name='title' id='title' value="<?php echo pt_data_html("title") ?>" onchange='highlightUpdate()' size='60' /></td>
</tr>

<tr>
  <td class='pt_caption'>Paper (optional):</td>
  <td class='pt_entry'><input type='file' name='uploadedFile' accept='application/pdf' size='60' /></td>
  <td class='pt_hint'>Max size: <?php echo get_cfg_var("upload_max_filesize") ?>B</td>
</tr>

<tr>
  <td class='<?php echo pt_caption_class("abstract") ?>'>Abstract:</td>
  <td class='pt_entry'><textarea class='textlite' name='abstract' rows='5' onchange='highlightUpdate()'><?php echo pt_data_html("abstract") ?></textarea></td>
</tr>

<tr>
  <td class='<?php echo pt_caption_class("authorInformation") ?>'>Author&nbsp;information:</td>
  <td class='pt_entry'><textarea class='textlite' name='authorInformation' rows='5' onchange='highlightUpdate()'><?php echo pt_data_html("authorInformation") ?></textarea></td>
  <td class='pt_hint'>List the paper's authors one per line, including affiliations.  Example: <pre class='entryexample'>Bob Roberts (UCLA)
Ludwig van Beethoven (Colorado)
Zhang, Ping Yen (INRIA)</pre></td>
</tr>

<tr>
  <td class='<?php echo pt_caption_class("collaborators") ?>'>Collaborators:</td>
  <td class='pt_entry'><textarea class='textlite' name='collaborators' rows='5' onchange='highlightUpdate()'><?php echo pt_data_html("collaborators") ?></textarea></td>
  <td class='pt_hint'>List the authors' recent (~2 years) coauthors and collaborators, and any advisor or student relationships.  Be sure to include PC members when appropriate.  We use this information to avoid conflicts of interest when reviewers are assigned.  Use the same format as for authors, above.</td>
</tr>

<?php
if ($topicTable = topicTable(-1, 1))
    echo "<tr>\n  <td class='pt_caption'>Topics:</td>\n  <td class='pt_entry' id='topictable'>", $topicTable,
	"</td>\n  <td class='pt_hint'>Check any topics that apply to your submission.  This will help us match your paper with interested reviewers.</td>\n</tr>\n";
?>

<tr>
  <td class='pt_caption'></td>
  <td class='pt_entry'><input class='button_default' type='submit' value='Create Paper' name='submit' /><?php
    if (!$can_start)
	echo "<br/><input type='checkbox' name='override' value='1' />&nbsp;Override deadlines\n";
    ?>
  </td>
</tr>

</table>
</form>


<?php $Conf->footer() ?>
