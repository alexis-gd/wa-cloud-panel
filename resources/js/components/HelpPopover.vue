<template>
  <div class="help-wrap">
    <button class="help-btn" @click="pop.toggle($event)" :title="`Ayuda - ${title}`">
      <i class="pi pi-question-circle" />
    </button>

    <Popover ref="pop" class="help-pop">
      <div class="help-content">
        <p class="help-title">
          <i class="pi pi-question-circle help-title-icon" />
          {{ title }}
        </p>

        <ul class="help-list">
          <li v-for="(item, i) in items" :key="i" class="help-item">
            <i class="pi help-item-icon" :class="item.icon ?? 'pi-check-circle'" />
            <span>
              <strong v-if="item.label">{{ item.label }}: </strong>{{ item.text }}
            </span>
          </li>
        </ul>

        <div v-if="warning" class="help-warning">
          <i class="pi pi-exclamation-triangle" /> {{ warning }}
        </div>

        <div v-if="tip" class="help-tip">
          <i class="pi pi-lightbulb" /> {{ tip }}
        </div>
      </div>
    </Popover>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import Popover from 'primevue/popover';

defineProps({
  title:   { type: String, required: true },
  items:   { type: Array,  default: () => [] },
  warning: { type: String, default: null },
  tip:     { type: String, default: null },
});

const pop = ref(null);
</script>

<style scoped>
.help-wrap { display: inline-flex; align-items: center; }

.help-btn {
  background: none;
  border: none;
  cursor: pointer;
  color: var(--p-text-muted-color);
  font-size: 1.05rem;
  padding: 4px 6px;
  border-radius: 6px;
  line-height: 1;
  transition: color .15s, background .15s;
  display: flex;
  align-items: center;
}
.help-btn:hover { color: var(--p-primary-500); background: var(--p-primary-50); }

/* Popover interior */
.help-content { width: 300px; padding: 4px 2px; }

.help-title {
  font-size: .85rem;
  font-weight: 700;
  color: var(--p-text-color);
  margin: 0 0 12px;
  display: flex;
  align-items: center;
  gap: 6px;
}
.help-title-icon { color: var(--p-primary-500); font-size: .9rem; }

.help-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 8px; }

.help-item {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  font-size: .8rem;
  color: var(--p-text-color);
  line-height: 1.4;
}
.help-item-icon { font-size: .78rem; color: var(--p-primary-400); margin-top: 2px; flex-shrink: 0; }

.help-warning {
  margin-top: 12px;
  font-size: .78rem;
  color: var(--p-orange-700);
  background: var(--p-orange-50);
  border-radius: 6px;
  padding: 7px 10px;
  display: flex;
  align-items: flex-start;
  gap: 6px;
  line-height: 1.4;
}
.help-warning .pi { flex-shrink: 0; margin-top: 1px; }

.help-tip {
  margin-top: 10px;
  font-size: .78rem;
  color: var(--p-primary-700);
  background: var(--p-primary-50);
  border-radius: 6px;
  padding: 7px 10px;
  display: flex;
  align-items: flex-start;
  gap: 6px;
  line-height: 1.4;
}
.help-tip .pi { flex-shrink: 0; margin-top: 1px; }
</style>
