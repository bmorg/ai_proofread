--
-- Per-page editor sign-off state ("geprüft"). One row per page; absence = never
-- signed off. Status is *derived* (no stored status, no content hash): compare
-- proofed_at against the page's latest report run — see ReviewRepository.
--
CREATE TABLE tx_aiproofread_review (
    page_uid int(11) unsigned DEFAULT '0' NOT NULL,
    pid int(11) unsigned DEFAULT '0' NOT NULL,

    proofed_at int(11) unsigned DEFAULT '0' NOT NULL,
    proofed_by int(11) unsigned DEFAULT '0' NOT NULL,

    UNIQUE KEY page (page_uid)
);

--
-- Report history: one immutable row per generated report (one run). The page's
-- "Aktueller Report" is the newest row; "Report-Verlauf" lists them (decoding
-- report_json for the per-category breakdown). content_hash is metadata for the
-- bulk command's skip-unchanged check only, NOT for editor status.
--
CREATE TABLE tx_aiproofread_report (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,

    page_uid int(11) unsigned DEFAULT '0' NOT NULL,
    language_uid int(11) unsigned DEFAULT '0' NOT NULL,
    be_user int(11) unsigned DEFAULT '0' NOT NULL,

    model varchar(64) DEFAULT '' NOT NULL,
    report_json mediumtext,
    categories varchar(64) DEFAULT '' NOT NULL,

    cost_usd double(12,6) DEFAULT '0' NOT NULL,
    duration_ms int(11) unsigned DEFAULT '0' NOT NULL,
    content_hash varchar(64) DEFAULT '' NOT NULL,

    PRIMARY KEY (uid),
    KEY page (page_uid,crdate)
);

--
-- Async job queue. A "Report erstellen" click enqueues a pending row; a
-- Scheduler-run command (aiproofread:process-queue) drains it. A run is N+1 LLM
-- requests (one per element + one whole-page), too slow to do inline.
-- A row exists only while pending/running, or after an error (kept with the
-- message); a successful run deletes its row (the review snapshot is the result).
--
CREATE TABLE tx_aiproofread_queue (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,

    page_uid int(11) unsigned DEFAULT '0' NOT NULL,
    language_uid int(11) unsigned DEFAULT '0' NOT NULL,
    be_user int(11) unsigned DEFAULT '0' NOT NULL,

    status varchar(20) DEFAULT 'pending' NOT NULL,
    started_at int(11) unsigned DEFAULT '0' NOT NULL,
    error_message text,

    PRIMARY KEY (uid),
    KEY page (page_uid,language_uid),
    KEY pending (status,uid)
);

--
-- Append-only audit log: one row per LLM call (report generation), capturing
-- the full request and response for debugging and accountability.
--
CREATE TABLE tx_aiproofread_log (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,

    be_user int(11) unsigned DEFAULT '0' NOT NULL,
    page_uid int(11) unsigned DEFAULT '0' NOT NULL,
    report_uid int(11) unsigned DEFAULT '0' NOT NULL,
    element_uid int(11) unsigned DEFAULT '0' NOT NULL,
    element_label varchar(255) DEFAULT '' NOT NULL,

    model varchar(64) DEFAULT '' NOT NULL,
    provider varchar(64) DEFAULT '' NOT NULL,
    request_json longtext,
    response_json longtext,

    input_tokens int(11) unsigned DEFAULT '0' NOT NULL,
    output_tokens int(11) unsigned DEFAULT '0' NOT NULL,
    cost_usd double(12,6) DEFAULT '0' NOT NULL,
    duration_ms int(11) unsigned DEFAULT '0' NOT NULL,

    success smallint(5) unsigned DEFAULT '0' NOT NULL,
    error_message text,

    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY initiator (be_user),
    KEY created (crdate)
);
