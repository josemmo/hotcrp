<?php
// src/pages/p_users.php -- HotCRP people listing/editing page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Users_Page {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $viewer;
    /** @var Qrequest */
    public $qreq;
    /** @var array<string,string> */
    public $limits;
    /** @var list<int> */
    private $papersel;

    function __construct(Contact $viewer, Qrequest $qreq) {
        $this->conf = $viewer->conf;
        if ($viewer->contactId && $viewer->is_disabled()) {
            $viewer = new Contact(["email" => $viewer->email], $viewer->conf);
        }
        $this->viewer = $viewer;
        $this->qreq = $qreq;
        $this->papersel = SearchSelection::make($qreq)->selection();

        $this->limits = [];
        if ($viewer->can_view_pc()) {
            $this->limits["pc"] = "Program committee";
        }
        foreach ($this->conf->viewable_user_tags($viewer) as $t) {
            if ($t !== "pc")
                $this->limits["#$t"] = "#$t program committee";
        }
        if ($viewer->can_view_pc()
            && $viewer->isPC) {
            $this->limits["admin"] = "System administrators";
        }
        if ($viewer->can_view_pc()
            && $viewer->isPC
            && ($qreq->t === "pcadmin" || $qreq->t === "pcadminx")) {
            $this->limits["pcadmin"] = "PC and system administrators";
        }
        if ($viewer->privChair
            || ($viewer->isPC && $this->conf->setting("pc_seeallrev"))) {
            $this->limits["re"] = "All reviewers";
            $this->limits["ext"] = "External reviewers";
            $this->limits["extsub"] = "External reviewers who completed a review";
        }
        if ($viewer->isPC) {
            $this->limits["req"] = "External reviewers you requested";
        }
        if ($viewer->privChair
            || ($viewer->isPC
                && $this->conf->submission_blindness() === Conf::BLIND_NEVER)) {
            $this->limits["au"] = "Contact authors of submitted papers";
        }
        if ($viewer->privChair
            || ($viewer->isPC && $this->conf->time_pc_view_decision(true))) {
            $this->limits["auacc"] = "Contact authors of accepted papers";
        }
        if ($viewer->privChair
            || ($viewer->isPC
                && $this->conf->submission_blindness() === Conf::BLIND_NEVER
                && $this->conf->time_pc_view_decision(true))) {
            $this->limits["aurej"] = "Contact authors of rejected papers";
        }
        if ($viewer->privChair) {
            $this->limits["auuns"] = "Contact authors of non-submitted papers";
            $this->limits["all"] = "Active users";
        }
    }


    /** @return bool */
    private function handle_nameemail() {
        $result = $this->conf->qe("select * from ContactInfo where contactId?a", $this->papersel);
        $users = [];
        while (($user = Contact::fetch($result, $this->conf))) {
            $users[] = $user;
        }
        Dbl::free($result);
        usort($users, $this->conf->user_comparator());
        Contact::ensure_contactdb_users($this->conf, $users);

        $texts = [];
        $has_country = false;
        foreach ($users as $u) {
            $texts[] = $line = [
                "first" => $u->firstName,
                "last" => $u->lastName,
                "email" => $u->email,
                "affiliation" => $u->affiliation,
                "country" => $u->country()
            ];
            $has_country = $has_country || $line["country"] !== "";
        }
        $header = ["first", "last", "email", "affiliation"];
        if ($has_country) {
            $header[] = "country";
        }
        $this->conf->make_csvg("users")->select($header)->append($texts)->emit();
        return true;
    }


    /** @return bool */
    private function handle_pcinfo() {
        $result = $this->conf->qe("select * from ContactInfo where contactId?a", $this->papersel);
        $users = [];
        while (($user = Contact::fetch($result, $this->conf))) {
            $users[] = $user;
        }
        Dbl::free($result);
        usort($users, $this->conf->user_comparator());
        Contact::load_topic_interests($users);
        Contact::ensure_contactdb_users($this->conf, $users);

        // NB This format is expected to be parsed by profile.php's bulk upload.
        $tagger = new Tagger($this->viewer);
        $people = [];
        $has_preferred_email = $has_tags = $has_topics =
            $has_phone = $has_country = $has_disabled = false;
        $has = (object) [];
        foreach ($users as $user) {
            $row = [
                "first" => $user->firstName,
                "last" => $user->lastName,
                "email" => $user->email,
                "affiliation" => $user->affiliation,
                "country" => $user->country(),
                "phone" => $user->phone(),
                "disabled" => $user->is_disabled() ? "yes" : "",
                "collaborators" => rtrim($user->collaborators())
            ];
            $has_country = $has_country || $row["country"] !== "";
            $has_phone = $has_phone || ($row["phone"] ?? "") !== "";
            $has_disabled = $has_disabled || $user->is_disabled();
            if ($user->preferredEmail && $user->preferredEmail !== $user->email) {
                $row["preferred_email"] = $user->preferredEmail;
                $has_preferred_email = true;
            }
            if ($user->contactTags) {
                $row["tags"] = $tagger->unparse($user->contactTags);
                $has_tags = $has_tags || $row["tags"] !== "";
            }
            foreach ($user->topic_interest_map() as $t => $i) {
                $row["topic$t"] = $i;
                $has_topics = true;
            }
            $f = [];
            $dw = $user->defaultWatch;
            foreach (UserStatus::$watch_keywords as $kw => $bit) {
                if ($dw === 0) {
                    break;
                } else if (($dw & $bit) !== 0) {
                    $f[] = $kw;
                    $dw &= ~$bit;
                }
            }
            $row["follow"] = empty($f) ? "none" : join(" ", $f);
            if ($user->roles & (Contact::ROLE_PC | Contact::ROLE_ADMIN | Contact::ROLE_CHAIR)) {
                $r = array();
                if ($user->roles & Contact::ROLE_CHAIR) {
                    $r[] = "chair";
                }
                if ($user->roles & Contact::ROLE_PC) {
                    $r[] = "pc";
                }
                if ($user->roles & Contact::ROLE_ADMIN) {
                    $r[] = "sysadmin";
                }
                $row["roles"] = join(" ", $r);
            } else {
                $row["roles"] = "";
            }
            $people[] = $row;
        }

        $header = ["first", "last", "email", "affiliation"];
        if ($has_country) {
            $header[] = "country";
        }
        if ($has_phone) {
            $header[] = "phone";
        }
        if ($has_disabled) {
            $header[] = "disabled";
        }
        if ($has_preferred_email) {
            $header[] = "preferred_email";
        }
        $header[] = "roles";
        if ($has_tags) {
            $header[] = "tags";
        }
        $header[] = "collaborators";
        $header[] = "follow";
        $selection = $header;
        if ($has_topics) {
            foreach ($this->conf->topic_set() as $t => $tn) {
                $header[] = "topic: " . $tn;
                $selection[] = "topic$t";
            }
        }

        $this->conf->make_csvg("pcinfo")->select($selection, $header)->append($people)->emit();
        return true;
    }


    /** @return bool */
    private function handle_modify() {
        $modifyfn = $this->qreq->modifyfn;
        unset($this->qreq->fn, $this->qreq->modifyfn);

        if ($modifyfn === "disableaccount") {
            $j = UserActions::disable($this->viewer, $this->papersel);
            $ok_message = "Accounts disabled.";
        } else if ($modifyfn === "enableaccount") {
            $j = UserActions::enable($this->viewer, $this->papersel);
            $ok_message = "Accounts enabled.";
        } else if ($modifyfn === "sendaccount") {
            $j = UserActions::send_account_info($this->viewer, $this->papersel);
            $ok_message = "Account information sent.";
        } else {
            return false;
        }

        if (($j->ok ?? false) && ($j->warnings ?? false)) {
            $this->conf->warnMsg("<div>" . join('</div><div class="mt-2">', $j->warnings) . "</div>");
        }
        if (($j->ok ?? false)
            && $ok_message
            && ($modifyfn !== "sendaccount" || !($j->warnings ?? false))
            && (!isset($j->users) || !empty($j->users))) {
            $this->conf->confirmMsg($ok_message);
        }
        $this->conf->redirect_self($this->qreq);
        return true;
    }


    /** @return bool */
    private function handle_tags() {
        // check tags
        $tagger = new Tagger($this->viewer);
        $t1 = $errors = [];
        foreach (preg_split('/[\s,;]+/', (string) $this->qreq->tag) as $t) {
            if ($t === "") {
                /* nada */
            } else if (!($t = $tagger->check($t, Tagger::NOPRIVATE))) {
                $errors[] = $tagger->error_html();
            } else if (Tagger::base($t) === "pc") {
                $errors[] = "The “pc” user tag is set automatically for all PC members.";
            } else {
                $t1[] = $t;
            }
        }
        if (!empty($errors)) {
            Conf::msg_error(join("<br>", $errors));
            return false;
        } else if (!count($t1)) {
            $this->conf->warnMsg("Nothing to do.");
            return false;
        }

        // modify database
        Conf::$no_invalidate_caches = true;
        $users = [];
        if ($this->qreq->tagfn === "s") {
            // erase existing tags
            $likes = $removes = [];
            foreach ($t1 as $t) {
                list($tag, $index) = Tagger::unpack($t);
                $removes[] = $t;
                $likes[] = "contactTags like " . Dbl::utf8ci("'% " . sqlq_for_like($tag) . "#%'");
            }
            foreach (Dbl::fetch_first_columns(Dbl::qe("select contactId from ContactInfo where " . join(" or ", $likes))) as $cid) {
                $users[(int) $cid] = (object) ["id" => (int) $cid, "add_tags" => [], "remove_tags" => $removes];
            }
        }

        // account for request
        $key = $this->qreq->tagfn === "d" ? "remove_tags" : "add_tags";
        foreach ($this->papersel as $cid) {
            if (!isset($users[(int) $cid])) {
                $users[(int) $cid] = (object) ["id" => (int) $cid, "add_tags" => [], "remove_tags" => []];
            }
            $users[(int) $cid]->$key = array_merge($users[(int) $cid]->$key, $t1);
        }

        // apply modifications
        $us = new UserStatus($this->viewer);
        foreach ($users as $cid => $cj) {
            $us->save($cj);
        }
        Conf::$no_invalidate_caches = false;
        $this->conf->invalidate_caches(["pc" => true]);

        // report
        if ($us->has_error()) {
            Conf::msg_error($us->error_texts());
            return false;
        } else {
            $this->conf->confirmMsg("Tags saved.");
            unset($this->qreq->fn, $this->qreq->tagfn);
            $this->conf->redirect_self($this->qreq);
            return true;
        }
    }


    /** @return bool */
    private function handle_redisplay() {
        $sv = [];
        foreach (ContactList::$folds as $key) {
            $sv[] = "uldisplay.$key=" . ($this->qreq->get("show$key") ? 0 : 1);
        }
        foreach ($this->conf->all_review_fields() as $f) {
            if ($this->qreq["has_show{$f->id}"])
                $sv[] = "uldisplay.{$f->id}=" . ($this->qreq->get("show{$f->id}") ? 0 : 1);
        }
        if (isset($this->qreq->scoresort)) {
            $sv[] = "ulscoresort=" . ListSorter::canonical_short_score_sort($this->qreq->scoresort);
        }
        Session_API::setsession($this->viewer, join(" ", $sv));
        $this->conf->redirect_self($this->qreq);
        return true;
    }


    /** @return bool */
    private function handle_request() {
        $qreq = $this->qreq;
        if ($qreq->fn === "get"
            && $qreq->getfn === "nameemail") {
            return !empty($this->papersel)
                && $this->viewer->isPC
                && $this->handle_nameemail();
        }
        if ($qreq->fn === "get"
            && $qreq->getfn === "pcinfo") {
            return !empty($this->papersel)
                && $this->viewer->privChair
                && $this->handle_pcinfo();
        }
        if ($qreq->fn === "modify") {
            return $this->viewer->privChair
                && $qreq->valid_post()
                && !empty($this->papersel)
                && $this->handle_modify();
        }
        if ($qreq->fn === "tag") {
            return $this->viewer->privChair
                && $qreq->valid_post()
                && !empty($this->papersel)
                && in_array($qreq->tagfn, ["a", "d", "s"])
                && $this->handle_tags();
        }
        if ($qreq->redisplay) {
            return $this->handle_redisplay();
        }
    }

    private function render_query_form(ContactList $pl) {
        echo '<table id="contactsform">
<tr><td><div class="tlx"><div class="tld is-tla active" id="tla-default">';

        echo Ht::form($this->conf->hoturl("users"), ["method" => "get"]);
        if (isset($this->qreq->sort)) {
            echo Ht::hidden("sort", $this->qreq->sort);
        }
        echo Ht::select("t", $this->limits, $this->qreq->t, ["class" => "want-focus"]),
            " &nbsp;", Ht::submit("Go"), "</form>";

        echo '</div><div class="tld is-tla" id="tla-view">';

        // Display options
        echo Ht::form($this->conf->hoturl("users"), ["method" => "get"]);
        foreach (["t", "sort"] as $x) {
            if (isset($this->qreq[$x]))
                echo Ht::hidden($x, $this->qreq[$x]);
        }

        echo '<table><tr><td><strong>Show:</strong> &nbsp;</td>
      <td class="pad">';
        foreach (["tags" => "Tags",
                  "aff" => "Affiliations", "collab" => "Collaborators",
                  "topics" => "Topics"] as $fold => $text) {
            if (($pl->have_folds[$fold] ?? null) !== null) {
                $k = array_search($fold, ContactList::$folds) + 1;
                echo Ht::checkbox("show$fold", 1, $pl->have_folds[$fold],
                                  ["data-fold-target" => "foldul#$k", "class" => "uich js-foldup"]),
                    "&nbsp;", Ht::label($text), "<br />\n";
            }
        }
        echo "</td>";

        if (isset($pl->scoreMax)) {
            echo '<td class="pad">';
            $revViewScore = $this->viewer->permissive_view_score_bound();
            $uldisplay = ContactList::uldisplay($this->viewer);
            foreach ($this->conf->all_review_fields() as $f) {
                if ($f->view_score > $revViewScore
                    && $f->has_options
                    && $f->main_storage) {
                    $checked = strpos($uldisplay, $f->id) !== false;
                    echo Ht::checkbox("show{$f->id}", 1, $checked),
                        "&nbsp;", Ht::label($f->name_html),
                        Ht::hidden("has_show{$f->id}", 1), "<br />";
                }
            }
            echo "</td>";
        }

        echo "<td>", Ht::submit("redisplay", "Redisplay"), "</td></tr>\n";

        if (isset($pl->scoreMax)) {
            $ss = [];
            foreach (ListSorter::score_sort_selector_options() as $k => $v) {
                if (in_array($k, ["average", "variance", "maxmin"]))
                    $ss[$k] = $v;
            }
            echo '<tr><td colspan="3"><hr class="g"><b>Sort scores by:</b> &nbsp;',
                Ht::select("scoresort", $ss, ListSorter::canonical_long_score_sort($this->viewer->session("ulscoresort", "A"))),
                "</td></tr>";
        }
        echo "</table></form>";

        echo "</div></div></td></tr>\n";

        // Tab selectors
        echo '<tr><td class="tllx"><table><tr>
<td><div class="tll active"><a class="ui tla" href="">User selection</a></div></td>
<td><div class="tll"><a class="ui tla" href="#view">View options</a></div></td>
</tr></table></td></tr>
</table>', "\n\n";
    }


    private function render() {
        if ($this->qreq->t === "pc") {
            $title = "Program committee";
        } else if (str_starts_with($this->qreq->t, "#")) {
            $title = "#" . substr($this->qreq->t, 1) . " program committee";
        } else {
            $title = "Users";
        }
        $this->conf->header($title, "users", ["action_bar" => actionBar("account")]);


        $pl = new ContactList($this->viewer, true, $this->qreq);
        $pl_text = $pl->table_html($this->qreq->t,
            $this->conf->hoturl("users", ["t" => $this->qreq->t]),
            $this->limits[$this->qreq->t], 'uldisplay.');

        echo '<hr class="g">';
        if (count($this->limits) > 1) {
            $this->render_query_form($pl);
        }

        if ($this->viewer->privChair && $this->qreq->t == "pc") {
            $this->conf->infoMsg('<p><a href="' . $this->conf->hoturl("profile", "u=new&amp;role=pc") . '" class="btn">Create accounts</a></p>Select a PC member’s name to edit their profile or remove them from the PC.');
        } else if ($this->viewer->privChair && $this->qreq->t == "all") {
            $this->conf->infoMsg('<p><a href="' . $this->conf->hoturl("profile", "u=new") . '" class="btn">Create accounts</a></p>Select a user to edit their profile.  Select ' . Ht::img("viewas.png", "[Act as]") . ' to view the site as that user would see it.');
        }

        if ($pl->any->sel) {
            echo Ht::form($this->conf->hoturl("=users", ["t" => $this->qreq->t])),
                Ht::hidden("defaultfn", ""),
                Ht::hidden_default_submit("default", 1),
                isset($this->qreq->sort) ? Ht::hidden("sort", $this->qreq->sort) : "",
                Ht::unstash(),
                $pl_text,
                "</form>";
        } else {
            echo Ht::unstash(), $pl_text;
        }

        $this->conf->footer();
    }


    static function go(Contact $viewer, Qrequest $qreq) {
        $up = new Users_Page($viewer, $qreq);

        // check list type
        if (empty($up->limits)) {
            Multiconference::fail(403, ["title" => "Users"], "You can’t list users for this site.");
            return;
        }
        if (!isset($qreq->t) && $qreq->path_component(0)) {
            $qreq->t = $qreq->path_component(0);
        }
        if (isset($qreq->t) && !isset($up->limits[$qreq->t])) {
            if (str_starts_with($qreq->t, "pc:")
                && isset($up->limits["#" . substr($qreq->t, 3)])) {
                $qreq->t = "#" . substr($qreq->t, 3);
            } else if (isset($up->limits["#" . $qreq->t])) {
                $qreq->t = "#" . $qreq->t;
            } else if ($qreq->t === "#pc") {
                $qreq->t = "pc";
            } else if ($qreq->t === "pcadminx" && isset($up->limits["pcadmin"])) {
                $qreq->t = "pcadmin";
            }
        }
        if (!isset($qreq->t)) {
            reset($up->limits);
            $qreq->t = key($up->limits);
        }
        if (!isset($up->limits[$qreq->t])) {
            Multiconference::fail(403, ["title" => "Users"], "User list not found.");
            return;
        }

        // handle request
        if (isset($qreq["default"]) && $qreq->defaultfn) {
            $qreq->fn = $qreq->defaultfn;
        }
        if ($qreq->fn && ($p = strpos($qreq->fn, "/")) !== false) {
            $qreq[substr($qreq->fn, 0, $p) . "fn"] = substr($qreq->fn, $p + 1);
            $qreq->fn = substr($qreq->fn, 0, $p);
        }
        if ($up->handle_request()) {
            return;
        }

        // render
        $up->render();
    }
}