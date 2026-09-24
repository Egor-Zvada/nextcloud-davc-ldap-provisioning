<?php
/** @var array $_ */
script('davc_ldap_provisioning', 'admin_v3');
style('davc_ldap_provisioning', 'admin_v3');
$config = $_['config'];
$compat = $_['compatibility'];

$renderProfile = static function (array $profile) use ($l): void {
    $usersJson = json_encode($profile['target_users'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    $groupsJson = json_encode($profile['target_groups'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    ?>
    <section class="davc-profile is-collapsed" data-profile-id="<?php p($profile['id']); ?>">
        <div class="davc-profile-header">
            <button type="button" class="davc-profile-toggle" aria-expanded="false">
                <span class="davc-profile-heading">
                    <span class="davc-profile-chevron" aria-hidden="true">›</span>
                    <span>
                        <strong data-role="profile-title"><?php p($profile['name'] !== '' ? $profile['name'] : $l->t('New configuration')); ?></strong>
                        <code data-role="profile-id"><?php p($profile['id']); ?></code>
                    </span>
                </span>
                <span data-role="toggle-label"><?php p($l->t('Expand')); ?></span>
            </button>
        </div>

        <div class="davc-profile-body" hidden>
            <div class="davc-profile-toolbar">
                <label>
                    <input data-field="enabled" type="checkbox" value="1" <?php if ($profile['enabled']): ?>checked<?php endif; ?>>
                    <?php p($l->t('Enable this configuration')); ?>
                </label>
                <button type="button" class="button davc-remove-profile"><?php p($l->t('Remove configuration')); ?></button>
            </div>

            <p>
                <label><?php p($l->t('Configuration and DAV service name')); ?></label><br>
                <input data-field="name" type="text" maxlength="80" value="<?php p($profile['name']); ?>" required>
            </p>

            <h4><?php p($l->t('Credentials')); ?></h4>
            <p>
                <label><?php p($l->t('Credential source')); ?></label><br>
                <select data-field="credential_source">
                    <option value="ldap" <?php if ($profile['credential_source'] === 'ldap'): ?>selected<?php endif; ?>><?php p($l->t('LDAP attributes for each user')); ?></option>
                    <option value="static" <?php if ($profile['credential_source'] === 'static'): ?>selected<?php endif; ?>><?php p($l->t('One manually entered account')); ?></option>
                </select>
            </p>

            <div data-role="ldap-credentials">
                <p class="davc-help"><?php p($l->t('A selected LDAP user is skipped when either attribute is empty. No request is sent to the DAV server.')); ?></p>
                <div class="davc-grid">
                    <p>
                        <label><?php p($l->t('LDAP login attribute')); ?></label><br>
                        <input data-field="login_attribute" type="text" value="<?php p($profile['login_attribute']); ?>">
                    </p>
                    <p>
                        <label><?php p($l->t('LDAP password or app-secret attribute')); ?></label><br>
                        <input data-field="secret_attribute" type="text" value="<?php p($profile['secret_attribute']); ?>">
                    </p>
                </div>
            </div>

            <div data-role="static-credentials">
                <p class="davc-warning"><?php p($l->t('Every selected Nextcloud user will receive the same external DAV account. The password is encrypted with the Nextcloud server secret.')); ?></p>
                <div class="davc-grid">
                    <p>
                        <label><?php p($l->t('DAV account login')); ?></label><br>
                        <input data-field="static_login" type="text" maxlength="320" value="<?php p($profile['static_login']); ?>" autocomplete="off">
                    </p>
                    <p>
                        <label><?php p($l->t('DAV account password or app password')); ?></label><br>
                        <input data-field="static_secret" type="password" maxlength="4096" value="" autocomplete="new-password"
                               data-secret-set="<?php p($profile['static_secret_set'] ? '1' : '0'); ?>"
                               placeholder="<?php p($profile['static_secret_set'] ? $l->t('Password saved — leave blank to keep it') : $l->t('Enter password')); ?>">
                    </p>
                </div>
            </div>

            <h4><?php p($l->t('Users and groups')); ?></h4>
            <p>
                <label>
                    <input data-field="target_all" type="checkbox" value="1" <?php if ($profile['target_all']): ?>checked<?php endif; ?>>
                    <?php p($l->t('Apply to all Nextcloud users')); ?>
                </label>
            </p>
            <p class="davc-help"><?php p($l->t('When this option is off, select one or more users or groups below. There is no implicit test account.')); ?></p>
            <div class="davc-grid davc-target-grid" data-role="target-pickers">
                <div>
                    <label><?php p($l->t('Selected users')); ?></label>
                    <div class="davc-principal-picker" data-kind="users" data-values="<?php p($usersJson); ?>">
                        <div class="davc-principal-chips" data-role="chips"></div>
                        <input data-role="principal-search" type="text" autocomplete="off" placeholder="<?php p($l->t('Search users')); ?>">
                        <div class="davc-principal-results" data-role="results" role="listbox" hidden></div>
                    </div>
                </div>
                <div>
                    <label><?php p($l->t('Selected groups')); ?></label>
                    <div class="davc-principal-picker" data-kind="groups" data-values="<?php p($groupsJson); ?>">
                        <div class="davc-principal-chips" data-role="chips"></div>
                        <input data-role="principal-search" type="text" autocomplete="off" placeholder="<?php p($l->t('Search groups')); ?>">
                        <div class="davc-principal-results" data-role="results" role="listbox" hidden></div>
                    </div>
                </div>
            </div>

            <h4><?php p($l->t('DAV endpoint')); ?></h4>
            <div class="davc-grid">
                <p>
                    <label><?php p($l->t('Host')); ?></label><br>
                    <input data-field="host" type="text" value="<?php p($profile['host']); ?>" placeholder="dav.example.org" required>
                </p>
                <p>
                    <label><?php p($l->t('Port')); ?></label><br>
                    <input data-field="port" type="number" min="1" max="65535" value="<?php p($profile['port']); ?>" required>
                </p>
                <p>
                    <label><?php p($l->t('DAV path')); ?></label><br>
                    <input data-field="path" type="text" value="<?php p($profile['path']); ?>" placeholder="/" required>
                </p>
            </div>
            <p>
                <label>
                    <input data-field="secure_transport" type="checkbox" value="1" <?php if ($profile['secure_transport']): ?>checked<?php endif; ?>>
                    <?php p($l->t('Use HTTPS')); ?>
                </label>
            </p>

            <h4><?php p($l->t('Automatic collection selection')); ?></h4>
            <p>
                <label>
                    <input data-field="auto_enable_calendars" type="checkbox" value="1" <?php if ($profile['auto_enable_calendars']): ?>checked<?php endif; ?>>
                    <?php p($l->t('Automatically connect all discovered calendars')); ?>
                </label><br>
                <span class="davc-help"><?php p($l->t('Leave this off when users should select only the calendars they need in DAV Connector.')); ?></span>
            </p>
            <p>
                <label>
                    <input data-field="auto_enable_contacts" type="checkbox" value="1" <?php if ($profile['auto_enable_contacts']): ?>checked<?php endif; ?>>
                    <?php p($l->t('Automatically connect all discovered address books')); ?>
                </label><br>
                <span class="davc-help"><?php p($l->t('Leave this off when users should select address books manually in DAV Connector.')); ?></span>
            </p>

            <h4><?php p($l->t('Background provisioning for this configuration')); ?></h4>
            <p>
                <label>
                    <input data-field="background_enabled" type="checkbox" value="1" <?php if ($profile['background_enabled']): ?>checked<?php endif; ?>>
                    <?php p($l->t('Enable scheduled provisioning')); ?>
                </label>
            </p>
            <p>
                <label><?php p($l->t('Run interval in seconds')); ?></label><br>
                <input data-field="background_interval" type="number" min="300" max="86400" value="<?php p($profile['background_interval']); ?>" required>
            </p>
            <p class="davc-help"><?php p($l->t('Minimum interval is 300 seconds. Keep this off until a single-user dry run succeeds.')); ?></p>
        </div>
    </section>
    <?php
};
?>
<div id="davc-provisioning" class="section" data-max-profiles="20">
    <h2><?php p($l->t('DAVC Provisioning')); ?></h2>
    <p class="davc-status <?php p($compat['ok'] ? 'ok' : 'error'); ?>"><?php p($compat['message']); ?></p>
    <p class="davc-intro"><?php p($l->t('Create independent DAV configurations with credentials from LDAP or one manually entered account. Each configuration has its own recipients and schedule.')); ?></p>
    <p class="davc-warning"><?php p($l->t('Manual passwords are encrypted at rest and are never shown again. Use provider-specific app passwords whenever possible.')); ?></p>

    <form id="davc-form">
        <div class="davc-profiles-heading">
            <h3><?php p($l->t('DAV configurations')); ?></h3>
            <button id="davc-add-profile" type="button" class="button"><?php p($l->t('Add configuration')); ?></button>
        </div>
        <div id="davc-profiles">
            <?php foreach ($config['profiles'] as $profile): ?>
                <?php $renderProfile($profile); ?>
            <?php endforeach; ?>
        </div>

        <p class="davc-actions">
            <button type="submit" class="primary"><?php p($l->t('Save')); ?></button>
            <span id="davc-save-status" aria-live="polite"></span>
        </p>
    </form>

    <template id="davc-profile-template">
        <?php
        $renderProfile([
            'id' => '',
            'name' => '',
            'enabled' => true,
            'credential_source' => 'static',
            'login_attribute' => '',
            'secret_attribute' => '',
            'static_login' => '',
            'static_secret_set' => false,
            'target_all' => false,
            'target_users' => [],
            'target_groups' => [],
            'host' => '',
            'port' => 443,
            'path' => '/',
            'secure_transport' => true,
            'auto_enable_calendars' => false,
            'auto_enable_contacts' => false,
            'background_enabled' => false,
            'background_interval' => 1800,
        ]);
        ?>
    </template>

    <p class="davc-hint">
        <?php p($l->t('Test a selected user from the command line before enabling its schedule:')); ?><br>
        <code>sudo -u www-data php occ davc-ldap:provision USER --profile=PROFILE_ID --dry-run</code><br>
        <code>sudo -u www-data php occ davc-ldap:provision USER --profile=PROFILE_ID</code>
    </p>
    <p class="davc-help"><?php p($l->t('Removing a configuration never deletes an already-created DAV service. Disconnect it explicitly in DAV Connector if required.')); ?></p>
</div>
