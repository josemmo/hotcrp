<?php
// pages/p_profile.php -- HotCRP profile management page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Profile_Page {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $viewer;
    /** @var Qrequest
     * @readonly */
    public $qreq;

    /** @var Contact */
    public $user;
    /** @var UserStatus */
    public $ustatus;
    /** @var int */
    public $page_type = 0;
    /** @var string */
    public $topic;

    function __construct(Contact $viewer, Qrequest $qreq) {
        $this->conf = $viewer->conf;
        $this->viewer = $viewer;
        $this->qreq = $qreq;

        $this->user = $viewer;
        $this->ustatus = new UserStatus($viewer);
        $this->ustatus->set_user($viewer);
        $this->ustatus->qreq = $qreq;
    }


    private function find_user() {
        // analyze URL and request
        if ($this->qreq->u === null && ($this->qreq->user || $this->qreq->contact)) {
            $this->qreq->u = $this->qreq->user ? : $this->qreq->contact;
        }
        if (($p = $this->qreq->path_component(0)) !== null) {
            if (in_array($p, ["", "me", "self", "new", "bulk"])
                || strpos($p, "@") !== false
                || !$this->ustatus->cs()->canonical_group($p)) {
                if ($this->qreq->u === null) {
                    $this->qreq->u = urldecode($p);
                }
                if (($p = $this->qreq->path_component(1)) !== null
                    && $this->qreq->t === null) {
                    $this->qreq->t = $p;
                }
            } else if ($this->qreq->t === null) {
                $this->qreq->t = $p;
            }
        }
        if ($this->viewer->privChair && $this->qreq->new) {
            $this->qreq->u = "new";
        }
        if ($this->qreq->u === "self") {
            $this->qreq->u = "me";
        }

        // parse requested user
        $user = $this->viewer;
        $u = $this->qreq->u ?? "me";
        if ($this->viewer->privChair && $u !== "me") {
            if ($u === "new" || $u === "bulk") {
                $user = Contact::make($this->conf);
                $this->page_type = $u === "new" ? 1 : 2;
            } else if (ctype_digit($u)) {
                $user = $this->conf->user_by_id(intval($u));
            } else if ($u === "" && $this->qreq->search) {
                $this->conf->redirect_hoturl("users");
            } else if (($user = $this->conf->user_by_email($u))) {
                // got it
            } else if ($this->qreq->search) {
                $cs = new ContactSearch(ContactSearch::F_USER, $u, $this->viewer);
                if ($cs->user_ids()) {
                    $user = $this->conf->user_by_id(($cs->user_ids())[0]);
                    $list = new SessionList("u/all/" . urlencode($this->qreq->search), $cs->user_ids(), "“" . htmlspecialchars($u) . "”", $this->conf->hoturl_raw("users", ["t" => "all"], Conf::HOTURL_SITEREL));
                    $list->set_cookie($this->viewer);
                    $this->qreq->u = $user->email;
                } else {
                    $this->conf->error_msg("<0>User ‘{$u}’ not found");
                    unset($this->qreq->u);
                }
                $this->conf->redirect_self($this->qreq);
            }
        }
        if ($user && $user->contactId && $user->contactId === $this->viewer->contactId) {
            $user = $this->viewer;
        }

        // redirect if requested user isn't loaded user
        if ($u === "me") {
            if ($user !== $this->viewer) {
                unset($this->qreq->u);
                $this->conf->redirect_self($this->qreq);
            }
        } else if (!$user
                   || ($u !== null
                       && $u !== (string) $user->contactId
                       && strcasecmp($u, $user->email) !== 0
                       && ($user->contactId || $this->page_type === 0))
                   || (isset($this->qreq->profile_contactid)
                       && $this->qreq->profile_contactid !== (string) $user->contactId)) {
            if (!$user) {
                $this->conf->error_msg("<0>User not found");
            } else if (isset($this->qreq->save) || isset($this->qreq->savebulk)) {
                $this->conf->error_msg("<0>Changes not saved; your session has changed since you last reloaded this tab");
            }
            $this->conf->redirect_self($this->qreq, ["u" => null]);
        }

        // load initial information about new user from submissions
        if (($user !== $this->viewer || !$this->viewer->has_account_here())
            && $user->has_email()
            && !$user->firstName
            && !$user->lastName
            && !$user->affiliation
            && !$this->qreq->is_post()) {
            $result = $this->conf->qe_raw("select Paper.paperId, authorInformation from Paper join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId={$user->contactId} and PaperConflict.conflictType>=" . CONFLICT_AUTHOR . ")");
            while (($prow = PaperInfo::fetch($result, $this->viewer))) {
                foreach ($prow->author_list() as $au) {
                    if (strcasecmp($au->email, $user->email) == 0
                        && ($au->firstName || $au->lastName || $au->affiliation)) {
                        if (!$user->firstName && $au->firstName) {
                            $user->firstName = $au->firstName;
                        }
                        if (!$user->lastName && $au->lastName) {
                            $user->lastName = $au->lastName;
                        }
                        if (!$user->affiliation && $au->affiliation) {
                            $user->affiliation = $au->affiliation;
                        }
                    }
                }
            }
            Dbl::free($result);
        }

        // apply user to UserStatus
        $this->user = $user;
        $this->ustatus->set_user($user);
    }


    /** @param UserStatus $ustatus
     * @param ?Contact $acct
     * @return ?Contact */
    private function save_user($ustatus, $acct) {
        // check for missing fields
        UserStatus::normalize_name($ustatus->jval);
        if (!$acct && !isset($ustatus->jval->email)) {
            $ustatus->error_at("email", "<0>Email address required");
            return null;
        }

        // check email
        if (!$acct || strcasecmp($ustatus->jval->email, $acct->email)) {
            if ($acct && $acct->data("locked")) {
                $ustatus->error_at("email", "<0>This account is locked, so you can’t change its email address");
                return null;
            } else if (($new_acct = $this->conf->user_by_email($ustatus->jval->email))) {
                if (!$acct) {
                    $ustatus->jval->id = $new_acct->contactId;
                } else {
                    $ustatus->error_at("email", "<0>Email address ‘{$ustatus->jval->email}’ is already in use");
                    $ustatus->msg_at("email", "<5>You may want to <a href=\"" . $this->conf->hoturl("mergeaccounts") . "\">merge these accounts</a>.", MessageSet::INFORM);
                    return null;
                }
            } else if ($this->conf->external_login()) {
                if ($ustatus->jval->email === "") {
                    $ustatus->error_at("email", "<0>Username required");
                    return null;
                }
            } else if ($ustatus->jval->email === "") {
                $ustatus->error_at("email", "<0>Email address required");
                return null;
            } else if (!validate_email($ustatus->jval->email)) {
                $ustatus->error_at("email", "<0>Invalid email address ‘{$ustatus->jval->email}’");
                return null;
            } else if ($acct && !$acct->has_account_here()) {
                $ustatus->error_at("email", "<0>Your current account is only active on other HotCRP.com sites. Due to a server limitation, you can’t change your email until activating your account on this site.");
                return null;
            }
            if ($acct && (!$ustatus->viewer->privChair || $acct === $ustatus->viewer)) {
                assert($acct->contactId > 0);
                $old_preferredEmail = $acct->preferredEmail;
                $acct->preferredEmail = $ustatus->jval->email;
                $capability = new TokenInfo($this->conf, TokenInfo::CHANGEEMAIL);
                $capability->set_user($acct)->set_token_pattern("hcce[20]")->set_expires_after(259200);
                $capability->data = json_encode_db(["oldemail" => $acct->email, "uemail" => $ustatus->jval->email]);
                if (($token = $capability->create())) {
                    $rest = ["capability_token" => $token, "sensitive" => true];
                    $mailer = new HotCRPMailer($this->conf, $acct, $rest);
                    $prep = $mailer->prepare("@changeemail", $rest);
                } else {
                    $prep = null;
                }
                if ($prep->can_send()) {
                    $prep->send();
                    $ustatus->msg_at("email_confirm", "<0>Confirmation email sent to {$ustatus->jval->email}", MessageSet::MARKED_NOTE);
                    $ustatus->inform_at("email_confirm", "<0>Follow the instructions in the confirmation email to complete the process of changing your email address.");
                } else {
                    $ustatus->error_at("email", "<0>Email change not saved: confirmation email cannot be sent to {$ustatus->jval->email} at the moment");
                }
                // Save changes *except* for new email, by restoring old email.
                $ustatus->jval->email = $acct->email;
                $acct->preferredEmail = $old_preferredEmail;
            }
        }

        // save account
        return $ustatus->save($ustatus->jval, $acct);
    }


    /** @param string $text
     * @param string $filename */
    private function save_bulk($text, $filename) {
        $text = cleannl(convert_to_utf8($text));
        $filename = $filename ? htmlspecialchars($filename) . ":" : "line ";
        $ms = new MessageSet;
        $success = $nochanges = $notified = [];

        if (!preg_match('/\A[^\r\n]*(?:,|\A)(?:user|email)(?:[,\r\n]|\z)/', $text)
            && !preg_match('/\A[^\r\n]*,[^\r\n]*,/', $text)) {
            $tarr = CsvParser::split_lines($text);
            foreach ($tarr as &$t) {
                if (($t = trim($t)) && $t[0] !== "#" && $t[0] !== "%") {
                    $t = CsvGenerator::quote($t);
                }
                $t .= "\n";
            }
            unset($t);
            $text = join("", $tarr);
        }

        $csv = new CsvParser($text);
        $csv->set_filename($filename);
        $csv->set_comment_chars("#%");
        if (($line = $csv->next_list())) {
            if (preg_grep('/\A(?:email|user)\z/i', $line)) {
                $csv->set_header($line);
            } else if (count($line) == 1) {
                $csv->set_header(["user"]);
                $csv->unshift($line);
            } else {
                // interpolate a likely header
                $csv->unshift($line);
                $hdr = [];
                for ($i = 0; $i < count($line); ++$i) {
                    if (validate_email($line[$i])
                        && array_search("email", $hdr) === false) {
                        $hdr[] = "email";
                    } else if (strpos($line[$i], " ") !== false
                               && array_search("name", $hdr) === false) {
                        $hdr[] = "name";
                    } else if (preg_match('/\A(?:pc|chair|sysadmin|admin)\z/i', $line[$i])
                               && array_search("roles", $hdr) === false) {
                        $hdr[] = "roles";
                    } else if (array_search("name", $hdr) !== false
                               && array_search("affiliation", $hdr) === false) {
                        $hdr[] = "affiliation";
                    } else {
                        $hdr[] = "unknown" . count($hdr);
                    }
                }
                $csv->set_header($hdr);
                $mi = $ms->warning_at(null, "<5>Header missing, assuming ‘<code>" . join(",", $hdr) . "</code>’");
                $mi->landmark = $csv->landmark();
            }
        }

        $ustatus = new UserStatus($this->viewer);
        $ustatus->no_deprivilege_self = true;
        $ustatus->no_nonempty_profile = true;
        $ustatus->add_csv_synonyms($csv);

        while (($line = $csv->next_row())) {
            $ustatus->set_user(Contact::make($this->conf));
            $ustatus->clear_messages();
            $ustatus->jval = (object) ["id" => null];
            $ustatus->csvreq = $line;
            $ustatus->parse_csv_group("");
            $ustatus->notify = friendly_boolean($line["notify"]) ?? true;
            if (($saved_user = $this->save_user($ustatus, null))) {
                $url = $this->conf->hoturl("profile", "u=" . urlencode($saved_user->email));
                $x = "<a class=\"nb\" href=\"{$url}\">" . $saved_user->name_h(NAME_E) . "</a>";
                if ($ustatus->notified) {
                    $notified[] = $x;
                    $success[] = $x;
                } else if (!empty($ustatus->diffs)) {
                    $success[] = $x;
                } else {
                    $nochanges[] = $x;
                }
            }
            foreach ($ustatus->problem_list() as $mi) {
                $mi->landmark = $csv->landmark();
                $ms->append_item($mi);
            }
        }

        if (!empty($ustatus->unknown_topics)) {
            $ms->warning_at(null, $this->conf->_("<0>Unknown topics ignored (%#s)", array_keys($ustatus->unknown_topics)));
        }
        $mpos = 0;
        if (!empty($success)) {
            $ms->splice_item($mpos++, MessageItem::success($this->conf->_("<5>Accounts %#s saved", $success)));
        } else if ($ms->has_error()) {
            $ms->splice_item($mpos++, MessageItem::error($this->conf->_("<0>Changes not saved; please correct these errors and try again")));
        }
        if (!empty($notified)) {
            $ms->splice_item($mpos++, MessageItem::success($this->conf->_("<5>Accounts %#s activated with email notification", $notified)));
        }
        if (!empty($nochanges)) {
            $ms->splice_item($mpos++, new MessageItem(null, $this->conf->_("<5>No changes to accounts %#s", $nochanges), MessageSet::MARKED_NOTE));
        } else if (!$ms->has_message()) {
            $ms->splice_item($mpos++, new MessageItem(null, "<0>No changes", MessageSet::WARNING_NOTE));
        }
        $this->conf->feedback_msg($ms);
        return !$ms->has_error();
    }


    private function handle_save() {
        assert($this->user->is_empty() === ($this->page_type !== 0));

        // prepare UserStatus
        $this->ustatus->set_user($this->user);
        $this->ustatus->jval = (object) ["id" => $this->user->has_account_here() ? $this->user->contactId : "new"];
        $this->ustatus->no_deprivilege_self = true;
        if ($this->page_type !== 0) {
            $this->ustatus->no_nonempty_profile = true;
            $this->ustatus->no_nonempty_pc = true;
            $this->ustatus->notify = true;
        }

        // parse request
        $this->ustatus->request_group("");

        // save request
        $saved_user = $this->save_user($this->ustatus, $this->page_type !== 0 ? null : $this->user);

        // report messages
        $purl = $this->conf->hoturl("profile", ["u" => $saved_user ? $saved_user->email : null]);
        if ($this->ustatus->has_error()) {
            $this->ustatus->prepend_msg("<0>Changes not saved; please correct the highlighted errors and try again", 2);
        } else if ($this->ustatus->created && $this->ustatus->notified) {
            $this->ustatus->prepend_msg("<5>Account " . Ht::link($saved_user->name_h(NAME_E), $purl) . " created and notified", MessageSet::SUCCESS);
        } else if ($this->ustatus->created) {
            $this->ustatus->prepend_msg("<5>Account " . Ht::link($saved_user->name_h(NAME_E), $purl) . " created", MessageSet::SUCCESS);
            $this->ustatus->splice_msg(1, "<0>The user was not notified by email.", MessageSet::INFORM);
        } else {
            $pos = 0;
            if ($this->page_type !== 0) {
                $this->ustatus->splice_msg($pos++, "<5>User " . Ht::link($saved_user->name_h(NAME_E), $purl) . " already had an account on this site", MessageSet::WARNING_NOTE);
            }
            if ($this->page_type !== 0 || $this->user !== $this->viewer) {
                $diffs = " to " . commajoin(array_keys($this->ustatus->diffs));
            } else {
                $diffs = "";
            }
            if (empty($this->ustatus->diffs)) {
                if (!$this->ustatus->has_message_at("email_confirm")) {
                    $this->ustatus->splice_msg($pos++, "<0>No changes", MessageSet::MARKED_NOTE);
                }
            } else if ($this->ustatus->notified) {
                $this->ustatus->splice_msg($pos++, "<0>Changes saved{$diffs} and user notified", MessageSet::SUCCESS);
            } else {
                $this->ustatus->splice_msg($pos++, "<0>Changes saved{$diffs}", MessageSet::SUCCESS);
            }
        }
        $this->conf->feedback_msg($this->ustatus);

        // exit on error
        if ($this->ustatus->has_error()) {
            return;
        }

        // redirect on success
        if (isset($this->qreq->redirect)) {
            $this->conf->redirect();
        } else {
            $xcj = [];
            if ($this->page_type !== 0) {
                $roles = $this->ustatus->jval->roles ?? [];
                if (in_array("chair", $roles)) {
                    $xcj["pctype"] = "chair";
                } else if (in_array("pc", $roles)) {
                    $xcj["pctype"] = "pc";
                } else {
                    $xcj["pctype"] = "none";
                }
                if (in_array("sysadmin", $roles)) {
                    $xcj["ass"] = 1;
                }
                $xcj["contactTags"] = join(" ", $this->ustatus->jval->tags ?? []);
            }
            if ($this->ustatus->has_problem()) {
                $xcj["warning_fields"] = $this->ustatus->problem_fields();
            }
            $this->viewer->save_session("profile_redirect", $xcj);
            if ($this->user !== $this->viewer && $this->page_type === 0) {
                $this->conf->redirect_self($this->qreq, ["u" => $this->user->email]);
            } else {
                $this->conf->redirect_self($this->qreq);
            }
        }
    }

    private function handle_save_bulk() {
        if ($this->qreq->has_file("bulk")) {
            $text = $this->qreq->file_contents("bulk");
            if ($text === false) {
                $this->conf->error_msg("<0>Internal error: cannot read uploaded file");
                return;
            }
            $filename = $this->qreq->file_filename("bulk");
        } else {
            $text = $this->qreq->bulkentry;
            $filename = "";
        }
        if (trim($text) !== "" && trim($text) !== "Enter users one per line") {
            if ($this->save_bulk($text, $filename)) {
                $this->conf->redirect_self($this->qreq);
            }
        } else {
            $this->conf->feedback_msg(new MessageItem(null, "<0>No changes", MessageSet::MARKED_NOTE));
        }
    }

    private function handle_delete() {
        if (!$this->viewer->privChair) {
            $this->conf->error_msg("<0>Only administrators can delete accounts");
        } else if ($this->user === $this->viewer) {
            $this->conf->error_msg("<0>You can’t delete your own account");
        } else if (!$this->user->has_account_here()) {
            $this->conf->feedback_msg(new MessageItem(null, "<0>This user’s account is not active on this site", MessageSet::MARKED_NOTE));
        } else if ($this->user->data("locked")) {
            $this->conf->error_msg("<0>This account is locked and can’t be deleted");
        } else if (($tracks = UserStatus::user_paper_info($this->conf, $this->user->contactId))
                   && !empty($tracks->soleAuthor)) {
            $this->conf->feedback_msg([
                MessageItem::error("<5>This account can’t be deleted because it is sole contact for " . UserStatus::render_paper_link($this->conf, $tracks->soleAuthor)),
                MessageItem::inform("<0>You will be able to delete the account after deleting those papers or adding additional paper contacts.")
            ]);
        } else {
            $this->conf->q("insert into DeletedContactInfo set contactId=?, firstName=?, lastName=?, unaccentedName=?, email=?, affiliation=?", $this->user->contactId, $this->user->firstName, $this->user->lastName, $this->user->unaccentedName, $this->user->email, $this->user->affiliation);
            foreach (["ContactInfo", "PaperComment", "PaperConflict", "PaperReview",
                      "PaperReviewPreference", "PaperReviewRefused", "PaperWatch",
                      "ReviewRating", "TopicInterest"] as $table) {
                $this->conf->qe_raw("delete from $table where contactId={$this->user->contactId}");
            }
            // delete twiddle tags
            $assigner = new AssignmentSet($this->viewer, true);
            $assigner->parse("paper,tag\nall,{$this->user->contactId}~all#clear\n");
            $assigner->execute();
            // clear caches
            if ($this->user->isPC || $this->user->privChair) {
                $this->conf->invalidate_caches(["pc" => true]);
            }
            // done
            $this->conf->success_msg("<0>Account {$this->user->email} deleted");
            $this->viewer->log_activity_for($this->user, "Account deleted {$this->user->email}");
            $this->conf->redirect_hoturl("users", "t=all");
        }
    }

    function handle_request() {
        $this->find_user();
        if ($this->qreq->cancel) {
            $this->conf->redirect_self($this->qreq);
        } else if ($this->qreq->savebulk
                   && $this->qreq->page_type
                   && $this->qreq->valid_post()) {
            $this->handle_save_bulk();
        } else if ($this->qreq->save
                   && $this->qreq->valid_post()) {
            $this->handle_save();
        } else if ($this->qreq->merge
                   && $this->page_type === 0
                   && $this->user === $this->viewer) {
            $this->conf->redirect_hoturl("mergeaccounts");
        } else if ($this->qreq->delete
                   && $this->qreq->valid_post()) {
            $this->handle_delete();
        }
    }


    private function prepare_and_crosscheck() {
        // import properties from cdb
        if (($cdbu = $this->user->cdb_user())) {
            $this->user->import_prop($cdbu, true);
            if ($this->user->prop_changed()) {
                $this->user->save_prop();
            }
        }

        // handle session, adjust request
        if ($this->user->session("freshlogin")) {
            $this->user->save_session("freshlogin", null);
        }
        if (($prdj = $this->user->session("profile_redirect"))) {
            $this->user->save_session("profile_redirect", null);
            foreach ($prdj as $k => $v) {
                if ($k === "warning_fields") {
                    foreach ($v as $k) {
                        $this->ustatus->warning_at($k);
                    }
                } else {
                    $this->qreq->$k = $v;
                }
            }
        }
        if ($this->viewer->privChair
            && $this->page_type !== 0
            && empty($this->ustatus->jval->roles)
            && in_array($this->qreq->role, ["pc", "chair"])) {
            $this->ustatus->jval->roles = [$this->qreq->role];
        }

        // crosscheck
        if ($this->page_type === 0) {
            foreach ($this->ustatus->cs()->members("__crosscheck", "crosscheck_function") as $gj) {
                $this->ustatus->cs()->call_function($gj, $gj->crosscheck_function, $gj);
            }
        }
    }

    function print() {
        // canonicalize topic
        if ($this->page_type === 0
            && ($g = $this->ustatus->cs()->canonical_group($this->qreq->t ? : "main"))) {
            $this->topic = $g;
        } else {
            $this->topic = "main";
        }
        if ($this->qreq->t
            && $this->qreq->t !== $this->topic
            && $this->qreq->is_get()) {
            $this->qreq->t = $this->topic === "main" ? null : $this->topic;
            $this->conf->redirect_self($this->qreq);
        }
        $this->ustatus->cs()->set_root($this->topic);

        // set session list
        if ($this->page_type === 0
            && ($list = SessionList::load_cookie($this->viewer, "u"))
            && $list->set_current_id($this->user->contactId)) {
            $this->conf->set_active_list($list);
        }

        // check $use_req
        $use_req = (!$this->user->has_account_here() && isset($this->qreq->watchreview))
            || $this->ustatus->has_error();

        // maybe prepare & crosscheck
        $this->ustatus->user_json();
        if (!$use_req) {
            $this->prepare_and_crosscheck();
        }

        // set title
        if ($this->page_type === 2) {
            $title = "Bulk update";
        } else if ($this->page_type === 1) {
            $title = "New account";
        } else if ($this->user === $this->viewer) {
            $title = "Profile";
        } else {
            $title = $this->viewer->name_html_for($this->user) . " profile";
        }
        $this->conf->header($title, "account", [
            "title_div" => '<hr class="c">',
            "body_class" => "leftmenu",
            "action_bar" => actionBar("account"),
            "save_messages" => true
        ]);

        // start form
        $form_params = [];
        if ($this->page_type === 2) {
            $form_params["u"] = "bulk";
        } else if ($this->page_type === 1) {
            $form_params["u"] = "new";
        } else if ($this->user !== $this->viewer) {
            $form_params["u"] = $this->user->email;
        }
        $form_params["t"] = $this->qreq->t;
        if (isset($this->qreq->ls)) {
            $form_params["ls"] = $this->qreq->ls;
        }
        echo Ht::form($this->conf->hoturl("=profile", $form_params), [
            "id" => "form-profile",
            "class" => "need-unload-protection",
            "data-user" => $this->page_type ? null : $this->user->email
        ]);

        // left menu
        echo '<div class="leftmenu-left"><nav class="leftmenu-menu">',
            '<h1 class="leftmenu"><a href="" class="uic js-leftmenu q">Account</a></h1>',
            '<ul class="leftmenu-list">';

        if ($this->viewer->privChair) {
            foreach ([["New account", "new"], ["Bulk update", "bulk"], ["Your profile", null]] as $t) {
                if (!$t[1] && $this->page_type === 0 && $this->user === $this->viewer) {
                    continue;
                }
                $active = $t[1] && $this->page_type === ($t[1] === "new" ? 1 : 2);
                echo '<li class="leftmenu-item',
                    $active ? ' active' : ' ui js-click-child',
                    ' font-italic">';
                if ($active) {
                    echo $t[0];
                } else {
                    echo Ht::link($t[0], $this->conf->selfurl($this->qreq, ["u" => $t[1], "t" => null]));
                }
                echo '</li>';
            }
        }

        if ($this->page_type === 0) {
            $first = $this->viewer->privChair;
            foreach ($this->ustatus->cs()->members("", "title") as $gj) {
                echo '<li class="leftmenu-item',
                    $gj->name === $this->topic ? ' active' : ' ui js-click-child',
                    $first ? ' leftmenu-item-gap4' : '', '">';
                if ($gj->name === $this->topic) {
                    echo $gj->title;
                } else {
                    echo Ht::link($gj->title, $this->conf->selfurl($this->qreq, ["t" => $gj->name]));
                }
                echo '</li>';
                $first = false;
            }
        }

        echo '</ul>';

        if ($this->page_type === 0) {
            echo '<div class="leftmenu-if-left if-alert mt-5">',
                Ht::submit("save", "Save changes", ["class" => "btn-primary"]), '</div>';
        }

        echo '</nav></div>',
            '<main id="profilecontent" class="leftmenu-content main-column">';

        if ($this->page_type === 2) {
            echo '<h2 class="leftmenu">Bulk update</h2>';
        } else {
            echo Ht::hidden("profile_contactid", $this->user->contactId);
            if (isset($this->qreq->redirect)) {
                echo Ht::hidden("redirect", $this->qreq->redirect);
            }

            echo '<div id="foldaccount" class="';
            if ($this->qreq->pctype === "chair"
                || $this->qreq->pctype === "pc"
                || (!isset($this->qreq->pctype) && ($this->user->roles & Contact::ROLE_PC) !== 0)) {
                echo "fold1o fold2o";
            } else if ($this->qreq->ass
                       || (!isset($this->qreq->pctype) && ($this->user->roles & Contact::ROLE_ADMIN) !== 0)) {
                echo "fold1c fold2o";
            } else {
                echo "fold1c fold2c";
            }
            echo "\">";

            echo '<h2 class="leftmenu">';
            if ($this->page_type === 1) {
                echo 'New account';
            } else {
                if ($this->user !== $this->viewer) {
                    echo $this->viewer->reviewer_html_for($this->user), ' ';
                }
                echo htmlspecialchars($this->ustatus->cs()->get($this->topic)->title);
                if ($this->user->is_disabled()) {
                    echo ' <span class="n dim">(disabled)</span>';
                }
            }
            echo '</h2>';
        }

        if (($this->conf->report_saved_messages() < 1 || $use_req)
            && $this->ustatus->has_message()) {
            $this->conf->feedback_msg($this->ustatus);
        }

        if ($this->page_type === 2) {
            $this->ustatus->print_group("__bulk");
        } else {
            $this->ustatus->print_group($this->topic);
            if (false
                && $this->ustatus->is_auth_self()
                && $this->ustatus->cdb_user()) {
                echo '<div class="form-g"><div class="checki"><label><span class="checkc">',
                    Ht::checkbox("saveglobal", 1, $use_req ? !!$this->qreq->saveglobal : true, ["class" => "ignore-diff"]),
                    '</span>Update global profile</label></div></div>';
            }
            echo "</div>"; // foldaccount
        }

        echo "</main></form>";

        if ($this->page_type === 0) {
            Ht::stash_script('hotcrp.highlight_form_children("#form-profile")');
        }
        $this->conf->footer();
    }


    static function go(Contact $user, Qrequest $qreq) {
        if (isset($qreq->cancel)) {
            $user->conf->redirect_self($qreq);
        } else if ($qreq->changeemail && !$user->is_actas_user()) {
            ChangeEmail_Page::go($user, $qreq);
        } else if (!$user->is_signed_in()) {
            $user->escape();
        }

        $pp = new Profile_Page($user, $qreq);
        $pp->handle_request();
        $pp->print();
    }
}