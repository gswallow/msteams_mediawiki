<?php
class MSTeamsNotifications
{

  /**
   * Creates the container for an actionable message card.
   */
  static function createMessageCard($title, $author, $action, $details, $pageTarget = NULL, $editTarget = NULL, $talkTarget = NULL) 
  {

    global $wgTeamsTimeZone, $wgWikiLogoUrl;

    date_default_timezone_set($wgTeamsTimeZone);
    $timestamp = strftime('%Y-%m-%d %H:%M:%S');

    $activity = array(
      "activityImage" =>  $wgWikiLogoUrl,
      "activityTitle" => $title,
      "activitySubTitle" => $timestamp,
      "activityText" => "$author $action $details"
    );

    $card = array(
      "@type" => "MessageCard",
      "@context" => "http://schema.org/extensions",
      "summary" => "Ivy Tech Wiki Update",
      "title" => $title,
      "sections" => array($activity)
    );

    $actions = array();

    if (!$pageTarget == NULL) {
      array_unshift($actions, array("@type" => "OpenUri", "name" => "View in Browser", "targets" => array( array( "os" => "default", "uri" => $pageTarget))));
    }

    if (!$talkTarget == NULL) {
      array_unshift($actions, array( "@type" => "OpenUri", "name" => "Discuss on wiki", "targets" => array( array( "os" => "default", "uri" => $talkTarget))));
    }

    if (!$editTarget == NULL) {
      array_unshift($actions, array( "@type" => "OpenUri", "name" => "Edit in browser", "targets" => array( array( "os" => "default", "uri" => $editTarget))));
    }

    if (count($actions) > 0) {
      $card["potentialAction"] = $actions;
    }

    return $card;
  }

  /**
   * Creates a hash of user-related wiki links.
   */
  static function getTeamsUserText($user)
  {
    global $wgWikiUrl, $wgWikiUrlEnding, $wgWikiUrlEndingUserPage,
      $wgWikiUrlEndingUserTalkPage, $wgWikiUrlEndingUserContributions;
    
    $result = array("userInfoPage" => sprintf("%s%s%s%s", $wgWikiUrl, $wgWikiUrlEnding, $wgWikiUrlEndingUserPage, $user),
                 "userContribsPage" => sprintf("%s%s%s%s", $wgWikiUrl, $wgWikiUrlEnding, $wgWikiUrlEndingUserContributions, $user),
                 "userTalkPage" => sprintf("%s%s%s%s", $wgWikiUrl, $wgWikiUrlEnding, $wgWikiUrlEndingUserTalkPage, $user));
    return $result;
  }

  /**
   * Gets nice HTML text for article containing the link to article page
   * and also into edit, delete and article history pages.
   */
  static function getTeamsArticleText(WikiPage $article, $diff = false)
  {
    global $wgWikiUrl, $wgWikiUrlEnding, $wgWikiUrlEndingEditArticle,
      $wgWikiUrlEndingHistory, $wgWikiUrlEndingDiff;

    $prefix = $wgWikiUrl.$wgWikiUrlEnding.str_replace(" ", "_", $article->getTitle()->getFullText());

    # return an array
    $result = array("articlePage" => $prefix,
                    "articleEditPage" => sprintf("%s&%s", $prefix, $wgWikiUrlEndingEditArticle),
                    "articleHistoryPage" => sprintf("%s&%s", $prefix, $wgWikiUrlEndingHistory));
    if ($diff) {
      $result["articleDiffPage"] = sprintf("%s&%s", $prefix, $wgWikiUrlEndingDiff.$article->getRevision()->getID());
    }

    return $result;
  }

  /**
   * Gets nice HTML text for title object containing the link to article page
   * and also into edit, delete and article history pages.
   */
  static function getTeamsTitleText(Title $title)
  {
    global $wgWikiUrl, $wgWikiUrlEnding, $wgWikiUrlEndingEditArticle, $wgWikiUrlEndingHistory;

    $titleName = $title->getFullText();

    $result = array("articlePage" => sprintf("%s%s%s", $wgWikiUrl, $wgWikiUrlEnding, $titleName),
                    "articleEditPage" => sprintf("%s%s%s&%s", $wgWikiUrl, $wgWikiUrlEnding, $titleName, $wgWikiUrlEndingEditArticle),
                    "articleHistoryPage" => sprintf("%s%s%s&%s", $wgWikiUrl, $wgWikiUrlEnding, $titleName, $wgWikiUrlEndingHistory));

    return $result;
  }

  /**
   * Occurs after the save page request has been processed.
   * @see https://www.mediawiki.org/wiki/Manual:Hooks/PageContentSaveComplete
   */
  static function teams_article_saved(WikiPage $article, $user, $content, $summary, $isMinor, $isWatch, $section, $flags, $revision, $status, $baseRevId)
  {
    global $wgTeamsNotificationEditedArticle;
    global $wgTeamsIgnoreMinorEdits;
    if (!$wgTeamsNotificationEditedArticle) return;

    // Discard notifications from excluded pages
    global $wgTeamsExcludeNotificationsFrom;
    if (count($wgTeamsExcludeNotificationsFrom) > 0) {
      foreach ($wgTeamsExcludeNotificationsFrom as &$currentExclude) {
        if (0 === strpos($article->getTitle(), $currentExclude)) return;
      }
    }

    // Skip new articles that have view count below 1. Adding new articles is already handled in article_added function and
    // calling it also here would trigger two notifications!
    $isNew = $status->value['new']; // This is 1 if article is new
    if ($isNew == 1) {
      return true;
    }

    // Skip minor edits if user wanted to ignore them
    if ($isMinor && $wgTeamsIgnoreMinorEdits) return;
    
    if ( $article->getRevision()->getPrevious() == NULL ) {
      return; // Skip edits that are just refreshing the page
    }
    
    $author = sprintf("[%s](%s)", $user, self::getTeamsUserText($user)["userInfoPage"]);

    if ($isMinor) { 
      $title = "Minor Page Edit";
      $action = "made a minor edit to";
    } else {
      $title = "Page Edited";
      $action = "edited";
    }

    $details = $article->getTitle();
    $pageTarget = self::getTeamsArticleText($article, true)["articlePage"];
    $editTarget = self::getTeamsArticleText($article, true)["articleEditPage"];
    $talkTarget = self::getTeamsUserText($user, true)["userTalkPage"];
    $card = self::createMessageCard($title, $author, $action, $details, $pageTarget, $editTarget, $talkTarget);

    self::push_teams_notify($card);
    return true;
  }

  /**
   * Occurs after a new article has been created.
   * @see http://www.mediawiki.org/wiki/Manual:Hooks/ArticleInsertComplete
   */
  static function teams_article_inserted(WikiPage $article, $user, $text, $summary, $isminor, $iswatch, $section, $flags, $revision)
  {
    global $wgTeamsNotificationAddedArticle;
    if (!$wgTeamsNotificationAddedArticle) return;

    // Discard notifications from excluded pages
    global $wgTeamsExcludeNotificationsFrom;
    if (count($wgTeamsExcludeNotificationsFrom) > 0) {
      foreach ($wgTeamsExcludeNotificationsFrom as &$currentExclude) {
        if (0 === strpos($article->getTitle(), $currentExclude)) return;
      }
    }

    // Do not announce newly added file uploads as articles...
    if ($article->getTitle()->getNsText() == "File") return true;
    
    $author = sprintf("[%s](%s)", $user, self::getTeamsUserText($user)["userInfoPage"]);
    $title = "Page Created";
    $action = "created";
    $details = $article->getTitle();
    $pageTarget = self::getTeamsArticleText($article, true)["articlePage"];
    $editTarget = self::getTeamsArticleText($article, true)["articleEditPage"];
    $talkTarget = self::getTeamsUserText($user, true)["userTalkPage"];
    $card = self::createMessageCard($title, $author, $action, $details, $pageTarget, $editTarget, $talkTarget);

    self::push_teams_notify($card);
    return true;
  }

  /**
   * Occurs after the delete article request has been processed.
   * @see http://www.mediawiki.org/wiki/Manual:Hooks/ArticleDeleteComplete
   */
  static function teams_article_deleted(WikiPage $article, $user, $reason, $id)
  {
    global $wgTeamsNotificationRemovedArticle;
    if (!$wgTeamsNotificationRemovedArticle) return;

    // Discard notifications from excluded pages
    global $wgTeamsExcludeNotificationsFrom;
    if (count($wgTeamsExcludeNotificationsFrom) > 0) {
      foreach ($wgTeamsExcludeNotificationsFrom as &$currentExclude) {
        if (0 === strpos($article->getTitle(), $currentExclude)) return;
      }
    }

    $author = sprintf("[%s](%s)", $user, self::getTeamsUserText($user)["userInfoPage"]);
    $title = "Page Deleted";
    $action = "deleted";
    $details = $article->getTitle();
    $talkTarget = self::getTeamsUserText($user, true)["userTalkPage"];
    $card = self::createMessageCard($title, $author, $action, $details, NULL, NULL, $talkTarget);

    self::push_teams_notify($card);
    return true;
  }

  /**
   * Occurs after a page has been moved.
   * @see https://www.mediawiki.org/wiki/Manual:Hooks/TitleMoveComplete
   */
  static function teams_article_moved($title, $newtitle, $user, $oldid, $newid, $reason = null)
  {
    global $wgTeamsNotificationMovedArticle;
    if (!$wgTeamsNotificationMovedArticle) return;

    // Discard notifications from excluded pages
    global $wgTeamsExcludeNotificationsFrom;
    if (count($wgTeamsExcludeNotificationsFrom) > 0) {
      foreach ($wgTeamsExcludeNotificationsFrom as &$currentExclude) {
        if (0 === strpos($title, $currentExclude)) return;
        if (0 === strpos($newtitle, $currentExclude)) return;
      }
    }

    $author = sprintf("[%s](%s)", $user, self::getTeamsUserText($user)["userInfoPage"]);
    $title = "Page Moved";
    $action = "";
    $details = sprintf("moved %s to %s", $title, $newtitle);
    $pageTarget = self::getTeamsTitleText($newtitle)["articlePage"];
    $editTarget = self::getTeamsTitleText($newtitle)["articleEditPage"];

    $card = self::createMessageCard($title, $author, $action, $details, $pageTarget, $editTarget, NULL);

    self::push_teams_notify($card);
    return true;
  }

  /**
   * Called after a user account is created.
   * @see http://www.mediawiki.org/wiki/Manual:Hooks/AddNewAccount
   */
  static function teams_new_user_account($user, $byEmail)
  {
    global $wgTeamsNotificationNewUser, $wgTeamsShowNewUserEmail, $wgTeamsShowNewUserFullName, $wgTeamsShowNewUserIP;
    if (!$wgTeamsNotificationNewUser) return;

    $email = "";
    $realname = "";
    $ipaddress = "";
    try { $email = $user->getEmail(); } catch (Exception $e) {}
    try { $realname = $user->getRealName(); } catch (Exception $e) {}
    try { $ipaddress = $user->getRequest()->getIP(); } catch (Exception $e) {}
    $messageExtra = "";
    if ($wgTeamsShowNewUserEmail || $wgTeamsShowNewUserFullName || $wgTeamsShowNewUserIP) {
      $messageExtra = "(";
      if ($wgTeamsShowNewUserEmail) $messageExtra .= $email . ", ";
      if ($wgTeamsShowNewUserFullName) $messageExtra .= $realname . ", ";
      if ($wgTeamsShowNewUserIP) $messageExtra .= $ipaddress . ", ";
      $messageExtra = substr($messageExtra, 0, -2); // Remove trailing , 
      $messageExtra .= ")";
    }

    $message = sprintf(
      "New user account %s was just created %s",
      self::getTeamsUserText($user),
      $messageExtra);

    $author = "wiki.ivytech.edu";
    $title = "User Account Created";
    $details = $message;
    $action = "";
    $card = self::createMessageCard($title, $author, $action, $details, NULL, NULL, NULL);

    self::push_teams_notify($card);
    return true;
  }

  /**
   * Sends the message into Teams room.
   * @param message Message to be sent.
   * @param color Background color for the message. One of "green", "yellow" or "red". (default: yellow)
   * @see https://api.teams.com/incoming-webhooks
   */
  static function push_teams_notify($card)
  {
    global $wgTeamsIncomingWebhookUrl, $wgTeamsFromName, $wgTeamsSendMethod, $wgExcludedPermission, $wgSitename, $wgHTTPProxy;
    
    if ( $wgExcludedPermission != "" ) {
      if ( $user->isAllowed( $wgExcludedPermission ) )
      {
        return; // Users with the permission suppress notifications
      }
    }

    $teamsFromName = $wgTeamsFromName;
    if ( $teamsFromName == "" )
    {
      $teamsFromName = $wgSitename;
    }
    
    // Use file_get_contents to send the data. Note that you will need to have allow_url_fopen enabled in php.ini for this to work.
    if ($wgTeamsSendMethod == "file_get_contents") {
      $extradata = array(
        'http' => array(
        'header'  => "Content-type: application/json\r\n",
        'method'  => 'POST',
        'content' => json_encode($card)
        ),
      );
      $context = stream_context_create($extradata);
      $result = file_get_contents($wgTeamsIncomingWebhookUrl, false, $context);
    }
    // Call the Teams API through cURL (default way). Note that you will need to have cURL enabled for this to work.
    else {
      $h = curl_init();
      curl_setopt($h, CURLOPT_URL, $wgTeamsIncomingWebhookUrl);
      curl_setopt($h, CURLOPT_HTTPHEADER, array('Content-type: application/json'));
      curl_setopt($h, CURLOPT_POST, 1);
      curl_setopt($h, CURLOPT_POSTFIELDS, json_encode($card));
      // I know this shouldn't be done, but because it wouldn't otherwise work because of SSL...
      curl_setopt ($h, CURLOPT_SSL_VERIFYHOST, 0);
      curl_setopt ($h, CURLOPT_SSL_VERIFYPEER, 0);
      // Set proxy for the request if user had proxy URL set
      if ($wgHTTPProxy) {
        curl_setopt($h, CURLOPT_PROXY, $wgHTTPProxy);
        curl_setopt($h, CURLOPT_RETURNTRANSFER, true);
      }
      // ... Aaand execute the curl script!
      curl_exec($h);
      curl_close($h);
    }
  }
}
?>
