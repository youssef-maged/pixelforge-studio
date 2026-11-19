<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({ inheritAttrs: false });
defineProps<{ className?: HTMLAttributes['class'] }>();
</script>

<template>
    <!-- PixelForge Studio mark: a print on a contact-sheet frame, with its caption strip. -->
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" :class="className" v-bind="$attrs" aria-hidden="true">
        <rect x="3" y="3" width="26" height="26" rx="2.5" fill="none" stroke="currentColor" stroke-width="2.5"/><rect x="8" y="8" width="16" height="12" rx="1" fill="currentColor"/><rect x="8" y="22.5" width="7" height="2.5" rx="1" fill="currentColor"/><rect x="17" y="22.5" width="3" height="2.5" rx="1" fill="currentColor" opacity=".55"/>
    </svg>
</template>
