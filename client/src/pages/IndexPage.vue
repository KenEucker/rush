<template>
  <q-page tabindex="-1" class="page-content">
    <p class="text-overline text-primary">Ranger Unified Scheduling &amp; Hours</p>
    <h1 class="text-h4 q-mt-sm" tabindex="-1">
      {{ session.isManagement ? 'Management overview' : 'Ranger overview' }}
    </h1>
    <p class="text-body1">Welcome, {{ session.identity?.user.name }}.</p>
    <q-banner role="note" class="bg-blue-1 q-mb-lg">
      RUSH is being set up. Scheduling and hours tools are not available yet.
    </q-banner>
    <div class="row q-col-gutter-lg">
      <div class="col-12 col-md-6">
        <q-card flat bordered class="full-height">
          <q-card-section>
            <q-icon name="calendar_month" size="2rem" color="primary" />
            <h2 class="text-h6">Explore the calendar</h2>
            <p>Preview date navigation and time controls. No assignments are loaded or saved.</p>
            <q-btn to="/calendar" label="Open calendar preview" color="primary" no-caps />
          </q-card-section>
        </q-card>
      </div>
      <div class="col-12 col-md-6">
        <q-card flat bordered class="full-height">
          <q-card-section>
            <q-icon
              :name="session.isManagement ? 'admin_panel_settings' : 'person'"
              size="2rem"
              color="primary"
            />
            <h2 class="text-h6">
              {{ session.isManagement ? 'Management administration' : 'Your account' }}
            </h2>
            <p>
              {{
                session.isManagement
                  ? 'Open administration using your current sign-in. An internet connection is required.'
                  : 'Check your organization membership or sign out of this device.'
              }}
            </p>
            <q-btn
              v-if="session.isManagement"
              href="/admin"
              label="Open administration"
              color="primary"
              no-caps
              :disable="!online"
            />
            <q-btn v-else to="/account" label="View your account" color="primary" no-caps />
          </q-card-section>
        </q-card>
      </div>
    </div>
    <p v-if="!session.identity?.memberships.length" role="status" class="q-mt-lg">
      You have no active organization membership. Contact Management.
    </p>
  </q-page>
</template>

<script setup lang="ts">
import { useSessionStore } from '../stores/session';
import { useConnectivity } from '../composables/useConnectivity';
const session = useSessionStore();
const { online } = useConnectivity();
</script>
