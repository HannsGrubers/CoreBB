-- CoreBB fresh-install schema.
-- Generated from the v1.0.0 initial public release schema, structure only.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
SET NAMES utf8mb4;

CREATE TABLE `adminlogs` (
  `action` text COLLATE utf8mb4_unicode_ci,
  `id` int(11) NOT NULL,
  `userid` int(11) NOT NULL DEFAULT '0',
  `userlevel` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `admin_username` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `ip_address` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `action_type` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `description` text COLLATE utf8mb4_unicode_ci,
  `date_performed` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `adminnotes` (
  `addtime` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `id` int(11) NOT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `reason` text COLLATE utf8mb4_unicode_ci,
  `reporterid` int(11) NOT NULL DEFAULT '0',
  `userid` int(11) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `admin_tool_permissions` (
  `userid` int(11) NOT NULL DEFAULT '0',
  `tool_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `granted_by` int(11) NOT NULL DEFAULT '0',
  `granted_at` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `blogs_posts` (
  `id` int(11) NOT NULL,
  `posterid` int(11) NOT NULL DEFAULT '0',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `body` text COLLATE utf8mb4_unicode_ci,
  `posttime` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `ptd` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `approved` tinyint(1) NOT NULL DEFAULT '1',
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `posttimeraw` int(11) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `boards` (
  `id` int(11) NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `private` tinyint(1) NOT NULL DEFAULT '0',
  `legacy_source` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `secure_archive` tinyint(1) NOT NULL DEFAULT '0',
  `position` int(11) NOT NULL DEFAULT '0',
  `default_open` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `contact_mod_requests` (
  `id` int(11) NOT NULL,
  `userid` int(11) NOT NULL DEFAULT '0',
  `subject` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `message` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new',
  `created_at` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_ip` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `handled_by` int(11) NOT NULL DEFAULT '0',
  `handled_at` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `handler_note` text COLLATE utf8mb4_unicode_ci,
  `response_pm_id` int(11) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `corebb_rate_limits` (
  `id` int(10) UNSIGNED NOT NULL,
  `action` varchar(64) CHARACTER SET ascii NOT NULL,
  `actor_hash` char(64) CHARACTER SET ascii NOT NULL,
  `window_started` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `hits` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `last_hit` int(10) UNSIGNED NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `email_verifications` (
  `id` int(10) UNSIGNED NOT NULL,
  `userid` int(10) UNSIGNED NOT NULL,
  `email` varchar(255) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `created_at` varchar(32) NOT NULL,
  `expires_at` int(10) UNSIGNED NOT NULL,
  `verified_at` varchar(32) DEFAULT NULL,
  `ipaddress` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `favoriteboards` (
  `adddate` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `boardid` int(11) NOT NULL DEFAULT '0',
  `id` int(11) NOT NULL,
  `ownerid` int(11) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `forums` (
  `categoryid` int(11) NOT NULL DEFAULT '0',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `edittimer` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `id` int(11) NOT NULL,
  `lastpstdate` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `lastpstdatets` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `position` int(11) NOT NULL DEFAULT '0',
  `ptd` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `threadid` int(11) NOT NULL DEFAULT '0',
  `legacy_source` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `legacy_board_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `topiccount` int(11) NOT NULL DEFAULT '0',
  `postcount` int(11) NOT NULL DEFAULT '0',
  `private` tinyint(1) NOT NULL DEFAULT '0',
  `secure_archive` tinyint(1) NOT NULL DEFAULT '0',
  `legacy_category_id` int(11) NOT NULL DEFAULT '0',
  `legacy_category_title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `globalmessages` (
  `id` int(11) NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci,
  `poster` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `icons` (
  `id` int(11) NOT NULL,
  `filepath` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `filename` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `mime` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `userid` int(11) NOT NULL DEFAULT '0',
  `uploaded` tinyint(1) NOT NULL DEFAULT '0',
  `uploaded_at` int(11) NOT NULL DEFAULT '0',
  `approved` tinyint(1) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `mod_requests` (
  `id` int(11) NOT NULL,
  `postid` int(11) NOT NULL DEFAULT '0',
  `topicid` int(11) NOT NULL DEFAULT '0',
  `boardid` int(11) NOT NULL DEFAULT '0',
  `reporterid` int(11) NOT NULL DEFAULT '0',
  `reported_userid` int(11) NOT NULL DEFAULT '0',
  `reason_type` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `comments` text COLLATE utf8mb4_unicode_ci,
  `severity` tinyint(3) NOT NULL DEFAULT '1',
  `url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new',
  `created_at` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_ip` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `handled_by` int(11) NOT NULL DEFAULT '0',
  `handled_at` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `handler_note` text COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `password_resets` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `created_at` datetime NOT NULL,
  `expires_at` int(10) UNSIGNED NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `request_ip` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `pm_reports` (
  `id` int(11) NOT NULL,
  `pmid` int(11) NOT NULL DEFAULT '0',
  `reporterid` int(11) NOT NULL DEFAULT '0',
  `reported_userid` int(11) NOT NULL DEFAULT '0',
  `senderid` int(11) NOT NULL DEFAULT '0',
  `recieveid` int(11) NOT NULL DEFAULT '0',
  `reason_type` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `comments` text COLLATE utf8mb4_unicode_ci,
  `severity` tinyint(3) NOT NULL DEFAULT '1',
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new',
  `created_at` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_ip` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `handled_by` int(11) NOT NULL DEFAULT '0',
  `handled_at` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `handler_note` text COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `polls` (
  `id` int(11) NOT NULL,
  `topicid` int(11) NOT NULL DEFAULT '0',
  `question` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_by` int(11) NOT NULL DEFAULT '0',
  `created_at` int(11) NOT NULL DEFAULT '0',
  `is_closed` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `poll_options` (
  `id` int(11) NOT NULL,
  `pollid` int(11) NOT NULL DEFAULT '0',
  `option_text` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `position` int(11) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `poll_votes` (
  `id` int(11) NOT NULL,
  `pollid` int(11) NOT NULL DEFAULT '0',
  `optionid` int(11) NOT NULL DEFAULT '0',
  `userid` int(11) NOT NULL DEFAULT '0',
  `voted_at` int(11) NOT NULL DEFAULT '0',
  `ip_address` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `posts` (
  `author` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `boardid` int(11) NOT NULL DEFAULT '0',
  `body` text COLLATE utf8mb4_unicode_ci,
  `editdate` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `editedby` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `id` int(11) NOT NULL,
  `posterid` int(11) NOT NULL DEFAULT '0',
  `posttime` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `posttimeraw` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `ptd` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `threadid` int(11) NOT NULL DEFAULT '0',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `wasedited` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `postip` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `legacy_source` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `legacy_post_id` bigint(20) NOT NULL DEFAULT '0',
  `legacy_topic_id` bigint(20) NOT NULL DEFAULT '0',
  `legacy_board_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `editcount` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
  `deleted_at` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `deleted_by` int(11) NOT NULL DEFAULT '0',
  `delete_reason` text COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `privatemessages` (
  `datesent` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `id` int(11) NOT NULL,
  `markread` int(11) NOT NULL DEFAULT '0',
  `message` text COLLATE utf8mb4_unicode_ci,
  `recieveid` int(11) NOT NULL DEFAULT '0',
  `senderid` int(11) NOT NULL DEFAULT '0',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
  `deleted_at` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `deleted_by` int(11) NOT NULL DEFAULT '0',
  `delete_reason` text COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `private_board_access` (
  `id` int(10) UNSIGNED NOT NULL,
  `boardid` int(11) NOT NULL,
  `userid` int(11) NOT NULL,
  `granted_by` int(11) NOT NULL DEFAULT '0',
  `created_at` varchar(64) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `systemsettings` (
  `id` int(11) NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `setting` mediumtext COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `systemstyles` (
  `id` int(11) NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `file` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `topics` (
  `boardid` int(11) NOT NULL DEFAULT '0',
  `body` text COLLATE utf8mb4_unicode_ci,
  `id` int(11) NOT NULL,
  `lastpost` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `now` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `posterid` int(11) NOT NULL DEFAULT '0',
  `sticky` int(11) NOT NULL DEFAULT '0',
  `time` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `locked` tinyint(1) NOT NULL DEFAULT '0',
  `legacy_source` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `legacy_topic_id` bigint(20) NOT NULL DEFAULT '0',
  `legacy_board_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `replycount` int(11) NOT NULL DEFAULT '0',
  `postcount` int(11) NOT NULL DEFAULT '0',
  `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
  `deleted_at` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `deleted_by` int(11) NOT NULL DEFAULT '0',
  `delete_reason` text COLLATE utf8mb4_unicode_ci,
  `legacy_vn_topic_id` bigint(20) NOT NULL DEFAULT '0',
  `legacy_original_reply_count` int(11) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `unban_requests` (
  `id` int(11) NOT NULL,
  `userid` int(11) NOT NULL DEFAULT '0',
  `username` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `contact_email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `ip_address` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `request_text` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `admin_userid` int(11) NOT NULL DEFAULT '0',
  `admin_note` text COLLATE utf8mb4_unicode_ci,
  `created_at` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `updated_at` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `resolved_at` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `users` (
  `BoardPages` int(11) NOT NULL DEFAULT '0',
  `LockedBlog` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `ThreadPages` int(11) NOT NULL DEFAULT '0',
  `accesslevel` int(11) NOT NULL DEFAULT '0',
  `blog_posts` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `company` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `country` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `fname` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `id` int(11) NOT NULL,
  `jobtitle` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `lastip` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `lastlogindate` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `lastpost` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `lastpstdate` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `lname` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `pcode` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `posts` int(11) NOT NULL DEFAULT '0',
  `privemail` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `pubemail` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `regdate` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `sig1` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `sig2` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `sig3` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `sig4` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `sig5` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `username` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `userstyle` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `websiteurl` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `yahoomesngr` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `style` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `iconid` int(11) NOT NULL DEFAULT '0',
  `signature` text COLLATE utf8mb4_unicode_ci,
  `profiletitle` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `profpic` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `bio` text COLLATE utf8mb4_unicode_ci,
  `birthday` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `gender` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `aolid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `icqid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `msnid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `irc` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `approved` tinyint(1) NOT NULL DEFAULT '1',
  `legacy_source` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `legacy_user_id` int(11) NOT NULL DEFAULT '0',
  `legacy_remote_user_id` bigint(20) NOT NULL DEFAULT '0',
  `legacy_username` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `legacy_imported_at` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `vip_bg_color` varchar(7) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `vip_text_color` varchar(7) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `vip_strike` tinyint(1) NOT NULL DEFAULT '0',
  `vip_bold` tinyint(1) NOT NULL DEFAULT '0',
  `vip_italic` tinyint(1) NOT NULL DEFAULT '0',
  `vip_border` tinyint(1) NOT NULL DEFAULT '0',
  `vip_border_color` varchar(7) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `ban_reason` text COLLATE utf8mb4_unicode_ci,
  `banned_at` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `banned_by` int(11) NOT NULL DEFAULT '0',
  `unbanned_at` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `unbanned_by` int(11) NOT NULL DEFAULT '0',
  `is_archive_user` tinyint(1) NOT NULL DEFAULT '0',
  `legacy_identity_key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `legacy_original_post_count` int(11) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_login_tokens` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `selector` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` int(10) UNSIGNED NOT NULL,
  `created_at` int(10) UNSIGNED NOT NULL,
  `last_used_at` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `ip` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL DEFAULT '0',
  `actor_user_id` int(11) NOT NULL DEFAULT '0',
  `notification_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'general',
  `title` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `body` text COLLATE utf8mb4_unicode_ci,
  `target_url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_at` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `read_at` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `cleared_at` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `subject_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `subject_id` int(11) NOT NULL DEFAULT '0',
  `event_count` int(11) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_notification_mutes` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL DEFAULT '0',
  `notification_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `subject_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `subject_id` int(11) NOT NULL DEFAULT '0',
  `created_at` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_notification_settings` (
  `user_id` int(11) NOT NULL DEFAULT '0',
  `notifications_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `updated_at` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `adminlogs`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `adminnotes`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `admin_tool_permissions`
  ADD PRIMARY KEY (`userid`,`tool_key`),
  ADD KEY `idx_admin_tool_permissions_tool` (`tool_key`);

ALTER TABLE `blogs_posts`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `boards`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_boards_private_id` (`private`,`id`),
  ADD KEY `idx_boards_secure_archive_id` (`secure_archive`,`id`),
  ADD KEY `idx_boards_position_id` (`position`,`id`);

ALTER TABLE `contact_mod_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_contact_mod_status` (`status`),
  ADD KEY `idx_contact_mod_userid` (`userid`),
  ADD KEY `idx_contact_mod_created` (`created_at`),
  ADD KEY `idx_contact_mod_handled` (`handled_by`,`handled_at`);

ALTER TABLE `corebb_rate_limits`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_corebb_rate_action_actor` (`action`,`actor_hash`),
  ADD KEY `idx_corebb_rate_last_hit` (`last_hit`);

ALTER TABLE `email_verifications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_email_verifications_userid` (`userid`),
  ADD KEY `idx_email_verifications_token` (`token_hash`),
  ADD KEY `idx_email_verifications_expires` (`expires_at`);

ALTER TABLE `favoriteboards`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_favorite_owner_board` (`ownerid`,`boardid`);

ALTER TABLE `forums`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_forums_category_position` (`categoryid`,`position`),
  ADD KEY `idx_forums_legacy_board` (`legacy_board_id`),
  ADD KEY `idx_forums_private_category` (`private`,`categoryid`),
  ADD KEY `idx_forums_secure_archive_category` (`secure_archive`,`categoryid`),
  ADD KEY `idx_forums_archive_board` (`legacy_source`,`legacy_board_id`);

ALTER TABLE `globalmessages`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `icons`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `mod_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_mod_requests_status` (`status`),
  ADD KEY `idx_mod_requests_postid` (`postid`),
  ADD KEY `idx_mod_requests_reporterid` (`reporterid`),
  ADD KEY `idx_mod_requests_created` (`created_at`);

ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token_hash` (`token_hash`),
  ADD KEY `email` (`email`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `expires_at` (`expires_at`);

ALTER TABLE `pm_reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pm_reports_status` (`status`),
  ADD KEY `idx_pm_reports_pmid` (`pmid`),
  ADD KEY `idx_pm_reports_reporter` (`reporterid`),
  ADD KEY `idx_pm_reports_created` (`created_at`);

ALTER TABLE `polls`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_polls_topicid` (`topicid`),
  ADD KEY `idx_polls_created_by` (`created_by`);

ALTER TABLE `poll_options`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_poll_options_poll_position` (`pollid`,`position`);

ALTER TABLE `poll_votes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_poll_votes_user` (`pollid`,`userid`),
  ADD KEY `idx_poll_votes_option` (`optionid`),
  ADD KEY `idx_poll_votes_poll` (`pollid`);

ALTER TABLE `posts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_posts_legacy_post_id` (`legacy_post_id`),
  ADD KEY `idx_posts_thread_id` (`threadid`,`id`),
  ADD KEY `idx_posts_board_timeraw` (`boardid`,`posttimeraw`),
  ADD KEY `idx_posts_board_id` (`boardid`,`id`),
  ADD KEY `idx_posts_poster_id` (`posterid`),
  ADD KEY `idx_posts_ptd` (`ptd`),
  ADD KEY `idx_posts_legacy_post` (`legacy_source`,`legacy_post_id`),
  ADD KEY `idx_posts_legacy_topic` (`legacy_topic_id`),
  ADD KEY `idx_posts_legacy_board` (`legacy_board_id`),
  ADD KEY `idx_posts_deleted_thread` (`is_deleted`,`threadid`),
  ADD KEY `idx_posts_deleted_board` (`is_deleted`,`boardid`),
  ADD KEY `idx_posts_archive_post` (`legacy_source`,`legacy_post_id`);

ALTER TABLE `posts` ADD FULLTEXT KEY `ft_posts_title_body` (`title`,`body`);

ALTER TABLE `privatemessages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pm_receiver_read` (`recieveid`,`markread`,`id`),
  ADD KEY `idx_pm_sender` (`senderid`,`id`),
  ADD KEY `idx_pm_deleted_receiver_read` (`recieveid`,`is_deleted`,`markread`,`id`),
  ADD KEY `idx_pm_deleted_sender` (`senderid`,`is_deleted`,`id`);

ALTER TABLE `private_board_access`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_private_board_user` (`boardid`,`userid`),
  ADD KEY `idx_private_user_board` (`userid`,`boardid`),
  ADD KEY `idx_private_board_user` (`boardid`,`userid`);

ALTER TABLE `systemsettings`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `systemstyles`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `topics`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_topics_legacy_topic_id` (`legacy_topic_id`),
  ADD KEY `idx_topics_board_sticky_lastpost` (`boardid`,`sticky`,`lastpost`),
  ADD KEY `idx_topics_board_lastpost` (`boardid`,`lastpost`),
  ADD KEY `idx_topics_legacy_topic` (`legacy_source`,`legacy_topic_id`),
  ADD KEY `idx_topics_legacy_board` (`legacy_board_id`),
  ADD KEY `idx_topics_poster_time` (`posterid`,`time`),
  ADD KEY `idx_topics_deleted_board` (`is_deleted`,`boardid`),
  ADD KEY `idx_topics_archive_topic` (`legacy_source`,`legacy_topic_id`),
  ADD KEY `idx_topics_archive_vn_topic` (`legacy_vn_topic_id`);

ALTER TABLE `topics` ADD FULLTEXT KEY `ft_topics_title` (`title`);

ALTER TABLE `unban_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `userid` (`userid`),
  ADD KEY `status` (`status`),
  ADD KEY `created_at` (`created_at`);

ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_users_legacy_user_id` (`legacy_user_id`),
  ADD KEY `idx_users_username` (`username`),
  ADD KEY `idx_users_legacy_user` (`legacy_source`,`legacy_user_id`),
  ADD KEY `idx_users_archive_identity` (`legacy_source`,`legacy_identity_key`),
  ADD KEY `idx_users_archive_flag` (`is_archive_user`);

ALTER TABLE `user_login_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `selector` (`selector`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `expires_at` (`expires_at`);

ALTER TABLE `user_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notifications_user_clear` (`user_id`,`cleared_at`,`id`),
  ADD KEY `idx_notifications_user_created` (`user_id`,`created_at`),
  ADD KEY `idx_notifications_type` (`notification_type`);

ALTER TABLE `user_notification_mutes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_notification_mute` (`user_id`,`notification_type`,`subject_type`,`subject_id`),
  ADD KEY `idx_notification_mutes_user` (`user_id`,`id`);

ALTER TABLE `user_notification_settings`
  ADD PRIMARY KEY (`user_id`),
  ADD KEY `idx_notification_settings_enabled` (`notifications_enabled`);

ALTER TABLE `adminlogs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `adminnotes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `blogs_posts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `boards`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `contact_mod_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `corebb_rate_limits`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `email_verifications`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `favoriteboards`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `forums`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `globalmessages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `icons`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `mod_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `password_resets`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `pm_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `polls`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `poll_options`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `poll_votes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `posts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `privatemessages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `private_board_access`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `systemsettings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `systemstyles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `topics`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `unban_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `user_login_tokens`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `user_notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `user_notification_mutes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
