<script setup lang="ts">
type IconName =
  | 'arrow-down'
  | 'close'
  | 'file'
  | 'history'
  | 'microphone'
  | 'paperclip'
  | 'plus'
  | 'rag'
  | 'send'
  | 'settings'
  | 'undo'
  | 'user'
  | 'volume'
  | 'eye'
  | 'delete'
  | 'upload'
  | 'link'
  | 'disable'
  | 'enable'
  | 'check'
  | 'chevron-down'
  | 'play'
  | 'stop'
  | 'refresh'

const props = defineProps<{ name: IconName }>()

const paths: Record<IconName, string> = {
  check: 'M5 12l4 4L19 6',
  'chevron-down': 'M8 10l4 4 4-4',
  play: 'M8 5v14l11-7z',
  stop: 'M7 7h10v10H7z',
  refresh: 'M20 12a8 8 0 1 1-2.34-5.66M20 4v5h-5',
  eye: 'M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12zM12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6z',
  delete: 'M4 7h16M9 7V4h6v3m3 0-1 14H7L6 7M10 11v6M14 11v6',
  upload: 'M12 16V4m0 0L7 9m5-5 5 5M5 14v5h14v-5',
  link: 'M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71',
  disable: 'M18.364 18.364A9 9 0 0 0 5.636 5.636m12.728 12.728A9 9 0 0 1 5.636 5.636m12.728 12.728L5.636 5.636',
  enable: 'M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z',
  'arrow-down': 'M12 5v14m0 0 6-6m-6 6-6-6',
  close: 'M6 6l12 12M18 6 6 18',
  file: 'M7 3h7l4 4v14H7zM14 3v5h5',
  history: 'M4 5v5h5M5.5 9a8 8 0 1 1-.3 6M12 7v5l3 2',
  microphone: 'M12 3a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V6a3 3 0 0 0-3-3zM5 11a7 7 0 0 0 14 0M12 18v3M8 21h8',
  paperclip: 'm21.4 11.1-9.2 9.1a6 6 0 0 1-8.4-8.4L13 2.6a4 4 0 0 1 5.7 5.6l-9.2 9.2a2 2 0 0 1-2.9-2.8l8.5-8.5',
  plus: 'M12 5v14M5 12h14',
  rag: 'M4 5h6a3 3 0 0 1 3 3v12a3 3 0 0 0-3-3H4zM20 5h-4a3 3 0 0 0-3 3v12a3 3 0 0 1 3-3h4z',
  send: 'M4 4l17 8-17 8 3-8zm3 8h14',
  settings: 'M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6zm8 3 2-1-2-4-2 .5-2-2L16.5 3h-5'
    + 'L11 5.5l-2 2L7 7 5 11l2 1-2 1 2 4 2-.5 2 2 .5 2.5h5l.5-2.5 2-2 2 .5 2-4z',
  undo: 'M9 7 4 12l5 5M5 12h8a6 6 0 0 1 6 6',
  user: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm-7 9a7 7 0 0 1 14 0',
  volume: 'M5 10v4h3l4 4V6L8 10zm10-1a4 4 0 0 1 0 6m2-8a7 7 0 0 1 0 10',
}
</script>

<template>
  <svg class="claire-icon" viewBox="0 0 24 24" aria-hidden="true">
    <path
      :d="paths[props.name]"
      fill="none"
      stroke="currentColor"
      stroke-width="1.8"
      stroke-linecap="round"
      stroke-linejoin="round"
    />
  </svg>
</template>
