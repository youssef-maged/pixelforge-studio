<script setup lang="ts">
// A slowly drifting wall of real generations from this app (public/showcase), used on the landing and sign-in pages.
const SHOWCASE_COUNT = 4;
const prints = (offset: number) => Array.from({ length: 8 }, (_, i) => `/showcase/print-${((i + offset) % SHOWCASE_COUNT) + 1}.webp`);
const columns = [prints(0), prints(1), prints(2)];
</script>

<template>
    <div class="pointer-events-none absolute inset-0 grid grid-cols-3 gap-3 overflow-hidden p-3" aria-hidden="true">
        <div v-for="(column, index) in columns" :key="index" class="drift flex flex-col gap-3" :style="{ animationDuration: `${70 + index * 18}s`, animationDirection: index === 1 ? 'reverse' : 'normal' }">
            <img v-for="(src, i) in [...column, ...column]" :key="i" :src="src" alt="" class="aspect-square w-full rounded-xl object-cover" loading="lazy" />
        </div>
    </div>
</template>

<style scoped>
.drift {
    animation: drift linear infinite;
}

@keyframes drift {
    from { transform: translateY(0); }
    to { transform: translateY(-50%); }
}

@media (prefers-reduced-motion: reduce) {
    .drift { animation: none; }
}
</style>
