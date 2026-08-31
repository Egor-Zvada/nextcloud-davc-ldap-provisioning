<?php
/** @var array $_ */
script('davc_ldap_provisioning', 'admin');
style('davc_ldap_provisioning', 'admin');
$config = $_['config'];
$compat = $_['compatibility'];

$renderProfile = static function (array $profile) use ($l): void {
    ?>
    <section class="davc-ldap-profile" data-profile-id="<?php p($profile['id']); ?>">
        <div class="davc-ldap-profile-header">
            <div>
                <h3 data-role="profile-title"><?php p($profile['name'] !== '' ? $profile['name'] : $l->t('New configuration')); ?></h3>
                <code data-role="profile-id"><?php p($profile['id']); ?></code>
            </div>
            <button type="button" class="button davc-ldap-remove-profile"><?php p($l->t('Remove configuration')); ?></button>
        </div>

        <p>
            <input data-field="enabled" type="checkbox" value="1" <?php if ($profile['enabled']): ?>checked<?php endif; ?>>
            <label><?php p($l->t('Enable this configuration')); ?></label>
        </p>
        <p>
            <label><?php p($l->t('Configuration and DAV service name')); ?></label><br>
            <input data-field="name" type="text" maxlength="80" value="<?php p($profile['name']); ?>" required>
        </p>

        <h4><?php p($l->t('Per-user LDAP opt-in')); ?></h4>
        <p class="davc-ldap-help">
            <?php p($l->t('This configuration runs for a user only when both selected LDAP attributes contain values.')); ?>
        </p>
        <div class="davc-ldap-grid">
            <p>
                <label><?php p($l->t('LDAP login attribute')); ?></label><br>
                <input data-field="login_attribute" type="text" value="<?php p($profile['login_attribute']); ?>" required>
            </p>
            <p>
                <label><?php p($l->t('LDAP password or app-secret attribute')); ?></label><br>
                <input data-field="secret_attribute" type="text" value="<?php p($profile['secret_attribute']); ?>" required>
            </p>
        </div>

        <h4><?php p($l->t('DAV endpoint')); ?></h4>
        <div class="davc-ldap-grid">
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
            <input data-field="secure_transport" type="checkbox" value="1" <?php if ($profile['secure_transport']): ?>checked<?php endif; ?>>
            <label><?php p($l->t('Use HTTPS')); ?></label>
        </p>

        <h4><?php p($l->t('Automatic collection selection')); ?></h4>
        <p>
            <input data-field="auto_enable_calendars" type="checkbox" value="1" <?php if ($profile['auto_enable_calendars']): ?>checked<?php endif; ?>>
            <label><?php p($l->t('Automatically connect all discovered calendars')); ?></label><br>
            <span class="davc-ldap-help"><?php p($l->t('Leave this off when users should select only the calendars they need in DAV Connector.')); ?></span>
        </p>
        <p>
            <input data-field="auto_enable_contacts" type="checkbox" value="1" <?php if ($profile['auto_enable_contacts']): ?>checked<?php endif; ?>>
            <label><?php p($l->t('Automatically connect all discovered address books')); ?></label><br>
            <span class="davc-ldap-help"><?php p($l->t('Leave this off when users should select address books manually in DAV Connector.')); ?></span>
        </p>
    </section>
    <?php
};
?>
<div id="davc-ldap-provisioning" class="section" data-max-profiles="20">
    <h2><?php p($l->t('DAVC LDAP Provisioning')); ?></h2>
    <p class="davc-ldap-status <?php p($compat['ok'] ? 'ok' : 'error'); ?>">
        <?php p($compat['message']); ?>
    </p>
    <p class="davc-ldap-intro">
        <?php p($l->t('Create any number of independent Basic-auth DAV configurations. Each configuration can use its own LDAP attributes and DAV endpoint for calendars, contacts, or another account.')); ?>
    </p>
    <p class="davc-ldap-opt-in">
        <?php p($l->t('A user is skipped independently for each configuration when its login or secret LDAP attribute is empty. No remote request is made and existing DAV services are left untouched.')); ?>
    </p>
    <p class="davc-ldap-warning">
        <?php p($l->t('LDAP secret attributes may be readable by directory users depending on Active Directory permissions. Use app passwords and restrict read access where possible.')); ?>
    </p>

    <form id="davc-ldap-form">
        <section class="davc-ldap-global">
            <h3><?php p($l->t('Background provisioning')); ?></h3>
            <p>
                <input type="checkbox" id="davc-ldap-background-enabled" value="1" <?php if ($config['background_enabled']): ?>checked<?php endif; ?>>
                <label for="davc-ldap-background-enabled"><?php p($l->t('Enable background provisioning for all active configurations')); ?></label>
            </p>
            <p>
                <label for="davc-ldap-interval"><?php p($l->t('Provisioning interval in seconds')); ?></label><br>
                <input id="davc-ldap-interval" type="number" min="300" max="86400" value="<?php p($config['interval']); ?>" required>
            </p>
            <p class="davc-ldap-help"><?php p($l->t('Keep this disabled until every new configuration passes a single-user dry run.')); ?></p>
        </section>

        <div class="davc-ldap-profiles-heading">
            <h3><?php p($l->t('DAV configurations')); ?></h3>
            <button id="davc-ldap-add-profile" type="button" class="button"><?php p($l->t('Add configuration')); ?></button>
        </div>
        <div id="davc-ldap-profiles">
            <?php foreach ($config['profiles'] as $profile): ?>
                <?php $renderProfile($profile); ?>
            <?php endforeach; ?>
        </div>

        <p class="davc-ldap-actions">
            <button type="submit" class="primary"><?php p($l->t('Save')); ?></button>
            <span id="davc-ldap-save-status" aria-live="polite"></span>
        </p>
    </form>

    <template id="davc-ldap-profile-template">
        <?php
        $renderProfile([
            'id' => '',
            'name' => '',
            'enabled' => true,
            'login_attribute' => '',
            'secret_attribute' => '',
            'host' => '',
            'port' => 443,
            'path' => '/',
            'secure_transport' => true,
            'auto_enable_calendars' => false,
            'auto_enable_contacts' => false,
        ]);
        ?>
    </template>

    <p class="davc-ldap-hint">
        <?php p($l->t('Test from the command line before enabling background provisioning:')); ?><br>
        <code>sudo -u www-data php occ davc-ldap:profile:list</code><br>
        <code>sudo -u www-data php occ davc-ldap:provision USER --profile=PROFILE_ID --dry-run</code><br>
        <code>sudo -u www-data php occ davc-ldap:provision USER --profile=PROFILE_ID</code>
    </p>
    <p class="davc-ldap-help">
        <?php p($l->t('Removing a configuration never deletes an already-created DAV service. Disconnect it explicitly in DAV Connector if required.')); ?>
    </p>
</div>
