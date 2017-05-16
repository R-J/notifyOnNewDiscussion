<?php
$PluginInfo['notifyOnNewDiscussion'] = [
    'Name' => 'Notify On New Discussion',
    'Description' => 'Allows users to choose to be notified on new discussions. ',
    'Version' => '0.0.1',
    'RequiredApplications' => ['Vanilla' => '>= 2.3'],
    'SettingsPermission' => 'Garden.Settings.Manage',
    'SettingsUrl' => '/settings/notifyonnewdiscussion',
    'MobileFriendly' => true,
    'HasLocale' => true,
    'Author' => 'Robin Jurinka',
    'AuthorUrl' => 'http://vanillaforums.org/profile/r_j',
    'License' => 'MIT'
];

class NotifyOnNewDiscussionPlugin extends Gdn_Plugin {
    /**
     * Init config values ad db changes.
     *
     * @return void.
     */
    public function setup() {
        touchConfig([
            'notifyOnNewDiscussion.Sender' => c('Garden.Email.SupportAddress'),
            'notifyOnNewDiscussion.Recipient' => c('Garden.Email.SupportAddress')
        ]);
        $this->structure();
    }

    /**
     * Add column to User table.
     *
     * @return void.
     */
    public function structure() {
        Gdn::database()->structure()
            ->table('User')
            ->column('NotifyOnNewDiscussion', 'tinyint', 0)
            ->set();
    }

    /**
     * Simple settings page.
     *
     * @param SettingsController $sender Instance of the calling class.
     *
     * @return void.
     */
    public function settingsController_notifyOnNewDiscussion_create($sender) {
        $sender->permission('Garden.Settings.Manage');
        $sender->addSideMenu('dashboard/settings/plugins');
        $sender->setData('Title', t('Notify On New Discussions Settings'));
        $sender->setData('Description', t('notifyOnNewDiscussion.Description','
            <style>dt{font-weight:bold;display:inline}</style>
            You can use following placeholders in mail body:<br><ul>
            <li><strong>%1$s</strong> - Discussion authors name</li>
            <li><strong>%2$s</strong> - Link to discussion authors profile</li>
            <li><strong>%3$s</strong> - Discussion title</li>
            <li><strong>%4$s</strong> - Link to discussion</li>
            <li><strong>%5$s</strong> - Discussion text</li>
            <li><strong>%6$s</strong> - First X characters of discusion text (set length below)</li>
            <li><strong>%7$s</strong> - Name of your forum</li>
            <li><strong>%8$s</strong> - Link to your forums homepage</li></ul>
        '));

        $configurationModule = new ConfigurationModule($sender);
        $configurationModule->initialize([
            'notifyOnNewDiscussion.Sender' => [
                'Control' => 'TextBox',
                'LabelCode' => 'Sender Email Address',
                'Options' => [
                    'class' => 'InputBox BigInput',
                    'type' => 'email'
                ],
                'Description' => 'Please use a valid email address.'
            ],
            'notifyOnNewDiscussion.Recipient' => [
                'Control' => 'TextBox',
                'LabelCode' => 'Recipient Email Address',
                'Options' => [
                    'class' => 'InputBox BigInput',
                    'type' => 'email'
                ],
                'Description' => 'This email addresses will appear in the "To:" field. Users email addresses will be used in the "Bcc:" field.'
            ],
            'notifyOnNewDiscussion.Subject' => [
                'Control' => 'TextBox',
                'LabelCode' => 'Subject',
                'Options' => [
                    'class' => 'InputBox BigInput'
                ],
                'Description' => 'Same placeholders allowed as in mail body.'
            ],
            'notifyOnNewDiscussion.Body' => [
                'Control' => 'TextBox',
                'LabelCode' => 'Body',
                'Options' => [
                    'class' => 'InputBox BigInput',
                    'multiline' => true
                ],
                'Description' => 'See the available placeholders in the info above.'
            ],
            'notifyOnNewDiscussion.PreviewLength' => [
                'Control' => 'TextBox',
                'LabelCode' => 'PreviewLength',
                'Options' => [
                    'class' => 'InputBox',
                    'type' => 'number'
                ],
                'Description' => 'If you use the preview in subject or body, you can specify its length here.'
            ]
        ]);

        $configurationModule->renderAll();
    }

    /**
     * Add notification option.
     *
     * @param ProfileController $sender Instance of the calling class.
     *
     * @return void.
     */
    public function profileController_afterPreferencesDefined_handler($sender) {
        $sender->Preferences['Notifications']['Email.NewDiscussion'] = t('Notify me when people write a new discussion.');
    }

    /*
    public function vanillaController_notify_create() {
        $discussionModel = new DiscussionModel();

        $args = [];
        $args['Discussion'] = $discussionModel->getID(10);
        $args['FormPostValues']['IsNewDiscussion'] = true;

        $this->discussionModel_afterSaveDiscussion_handler('', $args);
    }
    */
    /**
     * Gather mail address and send out information.
     *
     * @param DiscussionModel $sender Instance of the calling class.
     * @param array $args Event arguments.
     *
     * @return void.
     */
    public function discussionModel_afterSaveDiscussion_handler($sender, $args) {
        // Only inform on new discussions, not on edited discussions.
        if ($args['FormPostValues']['IsNewDiscussion'] != true) {
            return;
        }

        // gather recipient email addresses.
        $recipients = $this->getUserEmailAddressByCategoryID(
            val('CategoryID', $args['Discussion']),
            $args['Discussion']->InsertUserID
        );
        if (count($recipients) == 0) {
            return;
        }

        // decho($recipients);

        // Prepare subject and body.
        $insertUser = Gdn::userModel()->getID($args['Discussion']->InsertUserID);
        $userUrl = userUrl($insertUser);
        $discussionUrl = discussionUrl($args['Discussion']);
        $preview = sliceString(
            Gdn_Format::plainText(
                $args['Discussion']->Body,
                $args['Discussion']->Format
            ),
            c('notifyOnNewDiscussion.PreviewLength', 160)
        );
        $homepage = url('');

        $subject = sprintf(
            c('notifyOnNewDiscussion.Subject'),
            $insertUser->Name,
            $userUrl,
            $args['Discussion']->Name,
            $discussionUrl,
            $args['Discussion']->Body,
            $preview,
            c('Garden.Title'),
            $homepage
        );
        // decho($subject);

        $body = sprintf(
            c('notifyOnNewDiscussion.Body'),
            $insertUser->Name,
            $userUrl,
            $args['Discussion']->Name,
            $discussionUrl,
            $args['Discussion']->Body,
            $preview,
            c('Garden.Title'),
            $homepage
        );
        // decho($body);

        $email = new Gdn_Email();
        $email->to(c('notifyOnNewDiscussion.Recipient'));
        $email->bcc($recipients);
        $email->subject($subject);
        $email->message($body);
        try {
            // decho($email->send());
            $email->send();
        } catch (Exception $ex) {
            decho($ex);
        }
    }

    /**
     * Save notification preference to user table.
     *
     * Info must be stored in extra table for better access.
     *
     * @param UserModel $sender Instance of the calling class.
     * @param array $args Event arguments.
     *
     * @return void.
     */
    public function userModel_beforeSaveSerialized_handler($sender, $args) {
        Gdn::userModel()->setField(
            $args['UserID'],
            'NotifyOnNewDiscussion',
            intval(val('Email.NewDiscussion', $args['Name'], 0))
        );
    }

    /**
     * Get all email addresses which should be notified.
     *
     * Respects category permissions.
     *
     * @param int $categoryID The category ID for permission calculations.
     *
     * @return array Recipient email addresses.
     */
    protected function getUserEmailAddressByCategoryID($categoryID, $userID = 0) {
        Gdn::sql()
            ->distinct()
            ->select('u.Email')
            ->from('User u') 
            ->join('UserRole ur', 'u.UserId = ur.UserID', 'left')
            ->where('u.NotifyOnNewDiscussion', 1)
            ->where('u.Banned', 0)
            ->where('u.Deleted', 0)
            ->where('u.UserID <>', $userID);

        if (c('Vanilla.Categories.Use', true) == true) {
            // Add restriction based on categories.
            Gdn::sql()
                ->join('Permission p', 'ur.RoleID = p.RoleID', 'left')
                ->join('Category c', 'p.JunctionID = c.PermissionCategoryID', 'left')
                ->where('c.CategoryID', $categoryID)
                ->where('p.`Vanilla.Discussions.View`', 1)
                ->where('p.JunctionTable', 'Category')
                ->where('JunctionColumn', 'PermissionCategoryID');
        }

        return array_column(Gdn::sql()->get()->resultArray(), 'Email');
    }
}
