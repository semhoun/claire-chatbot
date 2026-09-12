<script setup lang="ts">
import type { TelegramConfig } from '../options-types'
import ClaireIcon from './ClaireIcon.vue'
defineProps<{ data: TelegramConfig; busy: boolean }>()
const model = defineModel<string>({ required: true })
defineEmits<{ submit: [] }>()
</script>

<template>
  <form id="claire-telegram-config-form" class="claire-telegram-config" @submit.prevent="$emit('submit')">
    <div v-if="data.error || data.success" class="claire-telegram-alert" :class="data.error ? 'claire-telegram-alert--error' : 'claire-telegram-alert--success'" role="alert">
      <div class="claire-telegram-alert__icon"><ClaireIcon :name="data.error ? 'close' : 'enable'" /></div>
      <div class="claire-telegram-alert__content"><div class="claire-telegram-alert__title">{{ data.error ? 'Erreur' : 'Succès' }}</div><div class="claire-telegram-alert__message">{{ data.error || data.success }}</div></div>
    </div>
    <div class="claire-telegram-status" :class="data.telegramId ? 'claire-telegram-status--linked' : 'claire-telegram-status--unlinked'">
      <div class="claire-telegram-status__header">
        <div class="claire-telegram-status__brand">
          <div class="claire-telegram-status__logo"><ClaireIcon name="send" /></div>
          <div class="claire-telegram-status__title-group"><div class="claire-telegram-status__title">Intégration Telegram</div><div class="claire-telegram-status__subtitle">Synchronisez Claire avec votre messagerie Telegram</div></div>
        </div>
        <div class="claire-telegram-badge" :class="data.telegramId ? 'claire-telegram-badge--success' : 'claire-telegram-badge--neutral'"><span class="claire-telegram-badge__dot"></span><span>{{ data.telegramId ? 'Compte associé' : 'Non associé' }}</span></div>
      </div>
      <div v-if="data.telegramId" class="claire-telegram-status__meta"><span class="claire-telegram-status__meta-label">ID Telegram associé :</span><code class="claire-telegram-status__meta-value">{{ data.telegramId }}</code></div>
    </div>
    <div class="claire-telegram-card">
      <label for="claire-telegram-id" class="claire-telegram-card__title">Identifiant Telegram (User ID)</label>
      <div class="claire-telegram-input-wrapper">
        <div class="claire-telegram-input-icon"><ClaireIcon name="user" /></div>
        <input id="claire-telegram-id" v-model="model" class="claire-chat-input__field claire-telegram-input" type="text" inputmode="numeric" placeholder="Ex : 123456789" autocomplete="off" :disabled="busy">
      </div>
      <p class="claire-telegram-help">{{ data.telegramId ? 'Pour dissocier votre compte Telegram, effacez ce champ et cliquez sur Enregistrer.' : 'Saisissez votre identifiant numérique unique pour lier vos conversations Telegram.' }}</p>
    </div>
    <div class="claire-telegram-card">
      <div class="claire-telegram-card__title">Comment obtenir votre ID Telegram ?</div>
      <div class="claire-telegram-steps">
        <div class="claire-telegram-step"><div class="claire-telegram-step__number">1</div><div class="claire-telegram-step__text">Ouvrez Telegram et recherchez le bot <a href="https://t.me/userinfobot" target="_blank" rel="noopener noreferrer" class="claire-telegram-chip">@userinfobot <ClaireIcon name="link" /></a> (ou <strong>@raw_data_bot</strong>).</div></div>
        <div class="claire-telegram-step"><div class="claire-telegram-step__number">2</div><div class="claire-telegram-step__text">Démarrez la conversation avec <code>/start</code>. Le bot vous envoie instantanément votre <strong>Id</strong> (ex : <code>123456789</code>).</div></div>
        <div class="claire-telegram-step"><div class="claire-telegram-step__number">3</div><div class="claire-telegram-step__text">Copiez cet identifiant numérique, collez-le dans le champ ci-dessus et cliquez sur <strong>Enregistrer</strong>.</div></div>
      </div>
    </div>
  </form>
</template>
