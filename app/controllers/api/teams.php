<?php

use Utopia\App;
use Utopia\Exception;
use Utopia\Config\Config;
use Utopia\Validator\Email;
use Utopia\Validator\Text;
use Utopia\Validator\Host;
use Utopia\Validator\Range;
use Utopia\Validator\ArrayList;
use Utopia\Validator\WhiteList;
use Appwrite\Auth\Auth;
use Appwrite\Database\Database;
use Appwrite\Database\Document;
use Appwrite\Database\Validator\UID;
use Appwrite\Database\Validator\Authorization;
use Appwrite\Database\Exception\Duplicate;
use Appwrite\Template\Template;
use Appwrite\Utopia\Response;
use DeviceDetector\DeviceDetector;

App::post('/v1/teams')
    ->desc('Create Team')
    ->groups(['api', 'teams'])
    ->label('scope', 'teams.write')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'create')
    ->label('sdk.description', '/docs/references/teams/create-team.md')
    ->param('name', null, function () { return new Text(100); }, 'Team name.')
    ->param('roles', ['owner'], function () { return new ArrayList(new Text(128)); }, 'Array of strings. Use this param to set the roles in the team for the user who created it. The default role is **owner**. A role can be any string. Learn more about [roles and permissions](/docs/permissions).', true)
    ->action(function ($name, $roles, $response, $user, $projectDB, $mode) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Document $user */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var bool $mode */

        Authorization::disable();

        $team = $projectDB->createDocument(Database::COLLECTION_TEAMS, [
            '$collection' => Database::COLLECTION_TEAMS,
            '$permissions' => [
                'read' => ['team:{self}'],
                'write' => ['team:{self}/owner'],
            ],
            'name' => $name,
            'sum' => ($mode !== APP_MODE_ADMIN && $user->getId()) ? 1 : 0,
            'dateCreated' => \time(),
        ]);

        Authorization::reset();

        if (false === $team) {
            throw new Exception('Failed saving team to DB', 500);
        }

        if ($mode !== APP_MODE_ADMIN && $user->getId()) { // Don't add user on server mode
            $membership = new Document([
                '$collection' => Database::COLLECTION_MEMBERSHIPS,
                '$permissions' => [
                    'read' => ['user:'.$user->getId(), 'team:'.$team->getId()],
                    'write' => ['user:'.$user->getId(), 'team:'.$team->getId().'/owner'],
                ],
                'userId' => $user->getId(),
                'teamId' => $team->getId(),
                'roles' => $roles,
                'invited' => \time(),
                'joined' => \time(),
                'confirm' => true,
                'secret' => '',
            ]);

            // Attach user to team
            $user->setAttribute('memberships', $membership, Document::SET_TYPE_APPEND);

            $user = $projectDB->updateDocument(Database::COLLECTION_USERS, $user->getId(), $user->getArrayCopy());

            if (false === $user) {
                throw new Exception('Failed saving user to DB', 500);
            }
        }

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic($team, Response::MODEL_TEAM);
    }, ['response', 'user', 'projectDB', 'mode']);

App::get('/v1/teams')
    ->desc('List Teams')
    ->groups(['api', 'teams'])
    ->label('scope', 'teams.read')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'list')
    ->label('sdk.description', '/docs/references/teams/list-teams.md')
    ->param('search', '', function () { return new Text(256); }, 'Search term to filter your list results.', true)
    ->param('limit', 25, function () { return new Range(0, 100); }, 'Results limit value. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, function () { return new Range(0, 2000); }, 'Results offset. The default value is 0. Use this param to manage pagination.', true)
    ->param('orderType', 'ASC', function () { return new WhiteList(['ASC', 'DESC']); }, 'Order result by ASC or DESC order.', true)
    ->action(function ($search, $limit, $offset, $orderType, $response, $projectDB) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */

        $results = $projectDB->find([
            'limit' => $limit,
            'offset' => $offset,
            'orderField' => 'dateCreated',
            'orderType' => $orderType,
            'orderCast' => 'int',
            'search' => $search,
            'filters' => [
                '$collection='.Database::COLLECTION_TEAMS,
            ],
        ]);

        $response->dynamic(new Document([
            'sum' => $projectDB->getSum(),
            'teams' => $results
        ]), Response::MODEL_TEAM_LIST);
    }, ['response', 'projectDB']);

App::get('/v1/teams/:teamId')
    ->desc('Get Team')
    ->groups(['api', 'teams'])
    ->label('scope', 'teams.read')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'get')
    ->label('sdk.description', '/docs/references/teams/get-team.md')
    ->param('teamId', '', function () { return new UID(); }, 'Team unique ID.')
    ->action(function ($teamId, $response, $projectDB) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */

        $team = $projectDB->getDocument(Database::COLLECTION_TEAMS, $teamId);

        if (empty($team->getId()) || Database::COLLECTION_TEAMS != $team->getCollection()) {
            throw new Exception('Team not found', 404);
        }

        $response->dynamic($team, Response::MODEL_TEAM);
    }, ['response', 'projectDB']);

App::put('/v1/teams/:teamId')
    ->desc('Update Team')
    ->groups(['api', 'teams'])
    ->label('scope', 'teams.write')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'update')
    ->label('sdk.description', '/docs/references/teams/update-team.md')
    ->param('teamId', '', function () { return new UID(); }, 'Team unique ID.')
    ->param('name', null, function () { return new Text(100); }, 'Team name.')
    ->action(function ($teamId, $name, $response, $projectDB) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */

        $team = $projectDB->getDocument(Database::COLLECTION_TEAMS, $teamId);

        if (empty($team->getId()) || Database::COLLECTION_TEAMS != $team->getCollection()) {
            throw new Exception('Team not found', 404);
        }

        $team = $projectDB->updateDocument(Database::COLLECTION_TEAMS, $team->getId(), \array_merge($team->getArrayCopy(), [
            'name' => $name,
        ]));

        if (false === $team) {
            throw new Exception('Failed saving team to DB', 500);
        }
        
        $response->dynamic($team, Response::MODEL_TEAM);
    }, ['response', 'projectDB']);

App::delete('/v1/teams/:teamId')
    ->desc('Delete Team')
    ->groups(['api', 'teams'])
    ->label('scope', 'teams.write')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'delete')
    ->label('sdk.description', '/docs/references/teams/delete-team.md')
    ->param('teamId', '', function () { return new UID(); }, 'Team unique ID.')
    ->action(function ($teamId, $response, $projectDB) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */

        $team = $projectDB->getDocument(Database::COLLECTION_TEAMS, $teamId);

        if (empty($team->getId()) || Database::COLLECTION_TEAMS != $team->getCollection()) {
            throw new Exception('Team not found', 404);
        }

        $memberships = $projectDB->find([
            'limit' => 2000, // TODO add members limit
            'offset' => 0,
            'filters' => [
                '$collection='.Database::COLLECTION_MEMBERSHIPS,
                'teamId='.$teamId,
            ],
        ]);

        foreach ($memberships as $member) {
            if (!$projectDB->deleteDocument(Database::COLLECTION_MEMBERSHIPS, $member->getId())) {
                throw new Exception('Failed to remove membership for team from DB', 500);
            }
        }

        if (!$projectDB->deleteDocument(Database::COLLECTION_TEAMS, $teamId)) {
            throw new Exception('Failed to remove team from DB', 500);
        }

        $response->noContent();
    }, ['response', 'projectDB']);

App::post('/v1/teams/:teamId/memberships')
    ->desc('Create Team Membership')
    ->groups(['api', 'teams'])
    ->label('scope', 'teams.write')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'createMembership')
    ->label('sdk.description', '/docs/references/teams/create-team-membership.md')
    ->param('teamId', '', function () { return new UID(); }, 'Team unique ID.')
    ->param('email', '', function () { return new Email(); }, 'New team member email.')
    ->param('name', '', function () { return new Text(100); }, 'New team member name.', true)
    ->param('roles', [], function () { return new ArrayList(new Text(128)); }, 'Array of strings. Use this param to set the user roles in the team. A role can be any string. Learn more about [roles and permissions](/docs/permissions).')
    ->param('url', '', function ($clients) { return new Host($clients); }, 'URL to redirect the user back to your app from the invitation email.  Only URLs from hostnames in your project platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', false, ['clients']) // TODO add our own built-in confirm page
    ->action(function ($teamId, $email, $name, $roles, $url, $response, $project, $user, $projectDB, $locale, $audits, $mails, $mode) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Document $project */
        /** @var Appwrite\Database\Document $user */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Event\Event $mails */
        /** @var bool $mode */

        $name = (empty($name)) ? $email : $name;
        $team = $projectDB->getDocument(Database::COLLECTION_TEAMS, $teamId);

        if (empty($team->getId()) || Database::COLLECTION_TEAMS != $team->getCollection()) {
            throw new Exception('Team not found', 404);
        }

        $memberships = $projectDB->find([
            'limit' => 50,
            'offset' => 0,
            'filters' => [
                '$collection='.Database::COLLECTION_MEMBERSHIPS,
                'teamId='.$team->getId(),
            ],
        ]);

        $invitee = $projectDB->findFirst([ // Get user by email address
            'limit' => 1,
            'filters' => [
                '$collection='.Database::COLLECTION_USERS,
                'email='.$email,
            ],
        ]);

        if (empty($invitee)) { // Create new user if no user with same email found

            Authorization::disable();

            try {
                $invitee = $projectDB->createDocument(Database::COLLECTION_USERS, [
                    '$collection' => Database::COLLECTION_USERS,
                    '$permissions' => [
                        'read' => ['user:{self}', '*'],
                        'write' => ['user:{self}'],
                    ],
                    'email' => $email,
                    'emailVerification' => false,
                    'status' => Auth::USER_STATUS_UNACTIVATED,
                    'password' => Auth::passwordHash(Auth::passwordGenerator()),
                    'password-update' => \time(),
                    'registration' => \time(),
                    'reset' => false,
                    'name' => $name,
                    'tokens' => [],
                ], ['email' => $email]);
            } catch (Duplicate $th) {
                throw new Exception('Account already exists', 409);
            }

            Authorization::reset();

            if (false === $invitee) {
                throw new Exception('Failed saving user to DB', 500);
            }
        }

        $isOwner = false;

        foreach ($memberships as $member) {
            if ($member->getAttribute('userId') ==  $invitee->getId()) {
                throw new Exception('User has already been invited or is already a member of this team', 409);
            }

            if ($member->getAttribute('userId') == $user->getId() && \in_array('owner', $member->getAttribute('roles', []))) {
                $isOwner = true;
            }
        }

        if (!$isOwner && APP_MODE_ADMIN !== $mode && $user->getId()) { // Not owner, not admin, not app (server)
            throw new Exception('User is not allowed to send invitations for this team', 401);
        }

        $secret = Auth::tokenGenerator();

        $membership = new Document([
            '$collection' => Database::COLLECTION_MEMBERSHIPS,
            '$permissions' => [
                'read' => ['*'],
                'write' => ['user:'.$invitee->getId(), 'team:'.$team->getId().'/owner'],
            ],
            'userId' => $invitee->getId(),
            'teamId' => $team->getId(),
            'roles' => $roles,
            'invited' => \time(),
            'joined' => (APP_MODE_ADMIN === $mode || !$user->getId()) ? \time() : 0,
            'confirm' => (APP_MODE_ADMIN === $mode || !$user->getId()),
            'secret' => Auth::hash($secret),
        ]);

        if (APP_MODE_ADMIN === $mode || !$user->getId()) { // Allow admin to create membership
            Authorization::disable();
            $membership = $projectDB->createDocument(Database::COLLECTION_MEMBERSHIPS, $membership->getArrayCopy());

            $team = $projectDB->updateDocument(Database::COLLECTION_TEAMS, $team->getId(), \array_merge($team->getArrayCopy(), [
                'sum' => $team->getAttribute('sum', 0) + 1,
            ]));

            // Attach user to team
            $invitee->setAttribute('memberships', $membership, Document::SET_TYPE_APPEND);

            $invitee = $projectDB->updateDocument(Database::COLLECTION_USERS, $invitee->getId(), $invitee->getArrayCopy());

            if (false === $invitee) {
                throw new Exception('Failed saving user to DB', 500);
            }

            Authorization::reset();
        } else {
            $membership = $projectDB->createDocument(Database::COLLECTION_MEMBERSHIPS, $membership->getArrayCopy());
        }

        if (false === $membership) {
            throw new Exception('Failed saving membership to DB', 500);
        }

        $url = Template::parseURL($url);
        $url['query'] = Template::mergeQuery(((isset($url['query'])) ? $url['query'] : ''), ['inviteId' => $membership->getId(), 'teamId' => $team->getId(), 'userId' => $invitee->getId(), 'secret' => $secret, 'teamId' => $teamId]);
        $url = Template::unParseURL($url);

        $body = new Template(__DIR__.'/../../config/locale/templates/email-base.tpl');
        $content = new Template(__DIR__.'/../../config/locale/translations/templates/'.$locale->getText('account.emails.invitation.body'));
        $cta = new Template(__DIR__.'/../../config/locale/templates/email-cta.tpl');

        $body
            ->setParam('{{content}}', $content->render())
            ->setParam('{{cta}}', $cta->render())
            ->setParam('{{title}}', $locale->getText('account.emails.invitation.title'))
            ->setParam('{{direction}}', $locale->getText('settings.direction'))
            ->setParam('{{project}}', $project->getAttribute('name', ['[APP-NAME]']))
            ->setParam('{{team}}', $team->getAttribute('name', '[TEAM-NAME]'))
            ->setParam('{{owner}}', $user->getAttribute('name', ''))
            ->setParam('{{redirect}}', $url)
            ->setParam('{{bg-body}}', '#f6f6f6')
            ->setParam('{{bg-content}}', '#ffffff')
            ->setParam('{{bg-cta}}', '#3498db')
            ->setParam('{{bg-cta-hover}}', '#34495e')
            ->setParam('{{text-content}}', '#000000')
            ->setParam('{{text-cta}}', '#ffffff')
        ;

        if (APP_MODE_ADMIN !== $mode && $user->getId()) { // No need in comfirmation when in admin or app mode
            $mails
                ->setParam('event', 'teams.membership.create')
                ->setParam('from', ($project->getId() === 'console') ? '' : \sprintf($locale->getText('account.emails.team'), $project->getAttribute('name')))
                ->setParam('recipient', $email)
                ->setParam('name', $name)
                ->setParam('subject', \sprintf($locale->getText('account.emails.invitation.title'), $team->getAttribute('name', '[TEAM-NAME]'), $project->getAttribute('name', ['[APP-NAME]'])))
                ->setParam('body', $body->render())
                ->trigger();
            ;
        }

        $audits
            ->setParam('userId', $invitee->getId())
            ->setParam('event', 'teams.membership.create')
            ->setParam('resource', 'teams/'.$teamId)
        ;

        $response->setStatusCode(Response::STATUS_CODE_CREATED);

        $response->dynamic(new Document(\array_merge($membership->getArrayCopy(), [
            'email' => $email,
            'name' => $name,
        ])), Response::MODEL_MEMBERSHIP);
    }, ['response', 'project', 'user', 'projectDB', 'locale', 'audits', 'mails', 'mode']);

App::get('/v1/teams/:teamId/memberships')
    ->desc('Get Team Memberships')
    ->groups(['api', 'teams'])
    ->label('scope', 'teams.read')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'getMemberships')
    ->label('sdk.description', '/docs/references/teams/get-team-members.md')
    ->param('teamId', '', function () { return new UID(); }, 'Team unique ID.')
    ->param('search', '', function () { return new Text(256); }, 'Search term to filter your list results.', true)
    ->param('limit', 25, function () { return new Range(0, 100); }, 'Results limit value. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, function () { return new Range(0, 2000); }, 'Results offset. The default value is 0. Use this param to manage pagination.', true)
    ->param('orderType', 'ASC', function () { return new WhiteList(['ASC', 'DESC']); }, 'Order result by ASC or DESC order.', true)
    ->action(function ($teamId, $search, $limit, $offset, $orderType, $response, $projectDB) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */

        $team = $projectDB->getDocument(Database::COLLECTION_TEAMS, $teamId);

        if (empty($team->getId()) || Database::COLLECTION_TEAMS != $team->getCollection()) {
            throw new Exception('Team not found', 404);
        }

        $memberships = $projectDB->find([
            'limit' => $limit,
            'offset' => $offset,
            'orderField' => 'joined',
            'orderType' => $orderType,
            'orderCast' => 'int',
            'search' => $search,
            'filters' => [
                '$collection='.Database::COLLECTION_MEMBERSHIPS,
                'teamId='.$teamId,
            ],
        ]);
        $users = [];

        foreach ($memberships as $membership) {
            if (empty($membership->getAttribute('userId', null))) {
                continue;
            }

            $temp = $projectDB->getDocument(Database::COLLECTION_MEMBERSHIPS, $membership->getAttribute('userId', null))->getArrayCopy(['email', 'name']);

            $users[] = new Document(\array_merge($temp, $membership->getArrayCopy()));
        }

        $response->dynamic(new Document(['sum' => $projectDB->getSum(), 'memberships' => $users]), Response::MODEL_MEMBERSHIP_LIST);
    }, ['response', 'projectDB']);

App::patch('/v1/teams/:teamId/memberships/:inviteId/status')
    ->desc('Update Team Membership Status')
    ->groups(['api', 'teams'])
    ->label('scope', 'public')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT])
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'updateMembershipStatus')
    ->label('sdk.description', '/docs/references/teams/update-team-membership-status.md')
    ->param('teamId', '', function () { return new UID(); }, 'Team unique ID.')
    ->param('inviteId', '', function () { return new UID(); }, 'Invite unique ID.')
    ->param('userId', '', function () { return new UID(); }, 'User unique ID.')
    ->param('secret', '', function () { return new Text(256); }, 'Secret key.')
    ->action(function ($teamId, $inviteId, $userId, $secret, $request, $response, $user, $projectDB, $geodb, $audits) {
        /** @var Utopia\Swoole\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Document $user */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var GeoIp2\Database\Reader $geodb */
        /** @var Appwrite\Event\Event $audits */

        $protocol = $request->getProtocol();
        $membership = $projectDB->getDocument(Database::COLLECTION_MEMBERSHIPS, $inviteId);

        if (empty($membership->getId()) || Database::COLLECTION_MEMBERSHIPS != $membership->getCollection()) {
            throw new Exception('Invite not found', 404);
        }

        if ($membership->getAttribute('teamId') !== $teamId) {
            throw new Exception('Team IDs don\'t match', 404);
        }

        Authorization::disable();

        $team = $projectDB->getDocument(Database::COLLECTION_TEAMS, $teamId);
        
        Authorization::reset();

        if (empty($team->getId()) || Database::COLLECTION_TEAMS != $team->getCollection()) {
            throw new Exception('Team not found', 404);
        }

        if (Auth::hash($secret) !== $membership->getAttribute('secret')) {
            throw new Exception('Secret key not valid', 401);
        }

        if ($userId != $membership->getAttribute('userId')) {
            throw new Exception('Invite not belong to current user ('.$user->getAttribute('email').')', 401);
        }

        if (empty($user->getId())) {
            $user = $projectDB->findFirst([ // Get user
                'limit' => 1,
                'filters' => [
                    '$collection='.Database::COLLECTION_USERS,
                    '$id='.$userId,
                ],
            ]);
        }

        if ($membership->getAttribute('userId') !== $user->getId()) {
            throw new Exception('Invite not belong to current user ('.$user->getAttribute('email').')', 401);
        }

        $membership // Attach user to team
            ->setAttribute('joined', \time())
            ->setAttribute('confirm', true)
        ;

        $user
            ->setAttribute('emailVerification', true)
            ->setAttribute('memberships', $membership, Document::SET_TYPE_APPEND)
        ;

        // Log user in

        $dd = new DeviceDetector($request->getUserAgent('UNKNOWN'));

        $dd->parse();

        $os = $dd->getOs();
        $osCode = (isset($os['short_name'])) ? $os['short_name'] : '';
        $osName = (isset($os['name'])) ? $os['name'] : '';
        $osVersion = (isset($os['version'])) ? $os['version'] : '';

        $client = $dd->getClient();
        $clientType = (isset($client['type'])) ? $client['type'] : '';
        $clientCode = (isset($client['short_name'])) ? $client['short_name'] : '';
        $clientName = (isset($client['name'])) ? $client['name'] : '';
        $clientVersion = (isset($client['version'])) ? $client['version'] : '';
        $clientEngine = (isset($client['engine'])) ? $client['engine'] : '';
        $clientEngineVersion = (isset($client['engine_version'])) ? $client['engine_version'] : '';

        $expiry = \time() + Auth::TOKEN_EXPIRATION_LOGIN_LONG;
        $secret = Auth::tokenGenerator();

        $session = new Document([
            '$collection' => Database::COLLECTION_TOKENS,
            '$permissions' => ['read' => ['user:'.$user->getId()], 'write' => ['user:'.$user->getId()]],
            'type' => Auth::TOKEN_TYPE_LOGIN,
            'secret' => Auth::hash($secret), // On way hash encryption to protect DB leak
            'expire' => $expiry,
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP(),

            'osCode' => $osCode,
            'osName' => $osName,
            'osVersion' => $osVersion,
            'clientType' => $clientType,
            'clientCode' => $clientCode,
            'clientName' => $clientName,
            'clientVersion' => $clientVersion,
            'clientEngine' => $clientEngine,
            'clientEngineVersion' => $clientEngineVersion,
            'deviceName' => $dd->getDeviceName(),
            'deviceBrand' => $dd->getBrandName(),
            'deviceModel' => $dd->getModel(),
        ]);

        try {
            $record = $geodb->country($request->getIP());
            $session
                ->setAttribute('countryCode', \strtolower($record->country->isoCode))
            ;
        } catch (\Exception $e) {
            $session
                ->setAttribute('countryCode', '--')
            ;
        }

        $user->setAttribute('tokens', $session, Document::SET_TYPE_APPEND);

        Authorization::setRole('user:'.$userId);

        $user = $projectDB->updateDocument(Database::COLLECTION_USERS, $user->getId(), $user->getArrayCopy());

        if (false === $user) {
            throw new Exception('Failed saving user to DB', 500);
        }

        Authorization::disable();

        $team = $projectDB->updateDocument(Database::COLLECTION_TEAMS, $team->getId(), \array_merge($team->getArrayCopy(), [
            'sum' => $team->getAttribute('sum', 0) + 1,
        ]));

        Authorization::reset();

        if (false === $team) {
            throw new Exception('Failed saving team to DB', 500);
        }

        $audits
            ->setParam('userId', $user->getId())
            ->setParam('event', 'teams.membership.update')
            ->setParam('resource', 'teams/'.$teamId)
        ;

        if (!Config::getParam('domainVerification')) {
            $response
                ->addHeader('X-Fallback-Cookies', \json_encode([Auth::$cookieName => Auth::encodeSession($user->getId(), $secret)]))
            ;
        }

        $response
            ->addCookie(Auth::$cookieName.'_legacy', Auth::encodeSession($user->getId(), $secret), $expiry, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, null)
            ->addCookie(Auth::$cookieName, Auth::encodeSession($user->getId(), $secret), $expiry, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, Config::getParam('cookieSamesite'))
        ;

        $response->dynamic(new Document(\array_merge($membership->getArrayCopy(), [
            'email' => $user->getAttribute('email'),
            'name' => $user->getAttribute('name'),
        ])), Response::MODEL_MEMBERSHIP);
    }, ['request', 'response', 'user', 'projectDB', 'geodb', 'audits']);

App::delete('/v1/teams/:teamId/memberships/:inviteId')
    ->desc('Delete Team Membership')
    ->groups(['api', 'teams'])
    ->label('scope', 'teams.write')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'deleteMembership')
    ->label('sdk.description', '/docs/references/teams/delete-team-membership.md')
    ->param('teamId', '', function () { return new UID(); }, 'Team unique ID.')
    ->param('inviteId', '', function () { return new UID(); }, 'Invite unique ID.')
    ->action(function ($teamId, $inviteId, $response, $projectDB, $audits) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Appwrite\Event\Event $audits */

        $membership = $projectDB->getDocument(Database::COLLECTION_MEMBERSHIPS, $inviteId);

        if (empty($membership->getId()) || Database::COLLECTION_MEMBERSHIPS != $membership->getCollection()) {
            throw new Exception('Invite not found', 404);
        }

        if ($membership->getAttribute('teamId') !== $teamId) {
            throw new Exception('Team IDs don\'t match', 404);
        }

        $team = $projectDB->getDocument(Database::COLLECTION_TEAMS, $teamId);

        if (empty($team->getId()) || Database::COLLECTION_TEAMS != $team->getCollection()) {
            throw new Exception('Team not found', 404);
        }

        if (!$projectDB->deleteDocument(Database::COLLECTION_MEMBERSHIPS, $membership->getId())) {
            throw new Exception('Failed to remove membership from DB', 500);
        }

        if ($membership->getAttribute('confirm')) { // Count only confirmed members
            $team = $projectDB->updateDocument(Database::COLLECTION_TEAMS, $team->getId(), \array_merge($team->getArrayCopy(), [
                'sum' => $team->getAttribute('sum', 0) - 1,
            ]));
        }

        if (false === $team) {
            throw new Exception('Failed saving team to DB', 500);
        }

        $audits
            ->setParam('userId', $membership->getAttribute('userId'))
            ->setParam('event', 'teams.membership.delete')
            ->setParam('resource', 'teams/'.$teamId)
        ;

        $response->noContent();
    }, ['response', 'projectDB', 'audits']);
