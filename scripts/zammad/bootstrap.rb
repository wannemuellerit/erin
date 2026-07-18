# frozen_string_literal: true

# This file runs inside the pinned Zammad Rails container. It intentionally
# receives configuration through process-local environment variables and
# prints only the newly generated Erin API token with a machine-readable marker.

admin_email = ENV.fetch('ERIN_ZAMMAD_ADMIN_EMAIL')
integration_email = ENV.fetch('ERIN_ZAMMAD_INTEGRATION_EMAIL')
group_name = ENV.fetch('ERIN_ZAMMAD_GROUP')
callback_url = ENV.fetch('ERIN_ZAMMAD_CALLBACK_URL')
webhook_secret = ENV.fetch('ERIN_ZAMMAD_WEBHOOK_SECRET')

raise 'Webhook secret is too short.' if webhook_secret.length < 32

admin = User.find_by(email: admin_email)
raise 'Configured Zammad administrator was not found.' if admin.nil?
raise 'Configured Zammad user is not an administrator.' unless admin.role?('Admin')

UserInfo.current_user_id = admin.id

token_value = nil

ActiveRecord::Base.transaction do
  group = Group.find_or_initialize_by(name: group_name)
  group.assign_attributes(
    active: true,
    follow_up_possible: 'yes',
    follow_up_assignment: true,
    note: 'Supporttickets aus Erin.',
    created_by_id: group.created_by_id || admin.id,
    updated_by_id: admin.id,
  )
  group.save!

  agent_role = Role.find_by!(name: 'Agent')
  integration_user = User.find_or_initialize_by(login: integration_email)
  integration_user.assign_attributes(
    firstname: 'Erin',
    lastname: 'Integration',
    email: integration_email,
    active: true,
    verified: true,
    roles: [agent_role],
    created_by_id: integration_user.created_by_id || admin.id,
    updated_by_id: admin.id,
  )
  integration_user.group_ids_access_map = { group.id => 'full' }
  integration_user.save!

  webhook = Webhook.find_or_initialize_by(name: 'Erin Support Bridge')
  webhook.assign_attributes(
    endpoint: callback_url,
    http_method: 'post',
    ssl_verify: callback_url.start_with?('https://'),
    signature_token: webhook_secret,
    active: true,
    customized_payload: false,
    note: 'Synchronisiert öffentliche Zammad-Antworten nach Erin.',
    created_by_id: webhook.created_by_id || admin.id,
    updated_by_id: admin.id,
  )
  webhook.save!

  trigger = Trigger.find_or_initialize_by(name: 'Erin Support Bridge')
  trigger.assign_attributes(
    activator: 'action',
    execution_condition_mode: 'selective',
    condition: {
      'ticket.group_id' => {
        'operator' => 'is',
        'value' => group.id,
      },
    },
    perform: {
      'notification.webhook' => {
        'webhook_id' => webhook.id,
      },
    },
    active: true,
    note: 'Übermittelt Änderungen aus der Erin-Support-Gruppe an Erin.',
    created_by_id: trigger.created_by_id || admin.id,
    updated_by_id: admin.id,
  )
  trigger.save!

  Token.where(
    user_id: integration_user.id,
    action: 'api',
    label: 'Erin local integration',
  ).delete_all
  token = Token.create!(
    user_id: integration_user.id,
    action: 'api',
    persistent: true,
    label: 'Erin local integration',
    preferences: { permission: ['ticket.agent'] },
  )
  token_value = token.token
end

raise 'Zammad did not generate an Erin API token.' if token_value.nil? || token_value.empty?

puts "ERIN_ZAMMAD_TOKEN=#{token_value}"
