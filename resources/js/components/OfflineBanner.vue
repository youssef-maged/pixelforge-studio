<script setup lang="ts">
import { useOnline } from '@vueuse/core';
import { WifiOff } from '@lucide/vue';

const online = useOnline();
</script>

<template>
    <Transition
        enter-from-class="-translate-y-full"
        leave-to-class="-translate-y-full"
        enter-active-class="transition-transform duration-200"
        leave-active-class="transition-transform duration-200"
    >
        <div
            v-if="!online"
            role="status"
            class="fixed inset-x-0 top-0 z-50 flex items-center justify-center gap-2 bg-destructive px-4 py-2 text-sm text-white"
        >
            <WifiOff class="size-4" />
            You're offline. Generations keep running at Pixelforge; this page
            updates when you're back.
        </div>
    </Transition>
</template>
