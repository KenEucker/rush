<template>
  <q-page class="foundation-page q-pa-lg">
    <section class="foundation-page__content">
      <p class="text-overline text-primary q-mb-sm">Ranger Unified Scheduling &amp; Hours</p>
      <h1 class="text-h3 text-weight-bold q-my-none">RUSH foundation</h1>
      <p class="text-body1 text-grey-8 q-mt-md q-mb-xl">
        The Quasar client is ready for the Ranger and Management workflows that follow in Milestone
        1.
      </p>

      <div class="row q-col-gutter-md">
        <div v-for="item in foundationItems" :key="item.label" class="col-12 col-sm-4">
          <q-item class="foundation-page__item">
            <q-item-section avatar>
              <q-icon :name="item.icon" color="primary" size="sm" />
            </q-item-section>
            <q-item-section>
              <q-item-label>{{ item.label }}</q-item-label>
              <q-item-label caption>{{ item.caption }}</q-item-label>
            </q-item-section>
          </q-item>
        </div>
      </div>
    </section>
  </q-page>
</template>

<script setup lang="ts">
const foundationItems = [
  {
    icon: 'dashboard',
    label: 'Quasar',
    caption: 'Vue 3 application shell',
  },
  {
    icon: 'offline_bolt',
    label: 'PWA',
    caption: 'Workbox mode installed',
  },
  {
    icon: 'inventory_2',
    label: 'Pinia',
    caption: 'Client state baseline',
  },
] as const;
</script>

<style scoped>
.foundation-page {
  display: flex;
  align-items: center;
  min-height: inherit;
  background: #f7f9fb;
}

.foundation-page__content {
  width: min(100%, 880px);
  margin: 0 auto;
}

.foundation-page__item {
  min-height: 84px;
  border: 1px solid #dce3ea;
  border-radius: 8px;
  background: #ffffff;
}
</style>
