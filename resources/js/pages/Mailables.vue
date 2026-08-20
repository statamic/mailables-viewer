<script setup>
import { computed, ref, watch } from 'vue';
import { debounce } from '@statamic/cms';
import { Head, useForm } from '@statamic/cms/inertia';
import {
    Header, Panel, Card, Input, Button, Badge, Heading, Text,
    SplitterGroup, SplitterPanel, SplitterResizeHandle,
    ToggleGroup, ToggleItem, ConfirmationModal, ErrorMessage, Field,
    EmptyStateMenu, EmptyStateItem, Avatar, Table, TableRow, TableCell,
    Switch,
} from '@statamic/cms/ui';

const props = defineProps({
    mailables: { type: Array, default: () => [] },
    previewUrl: { type: String, required: true },
    metaUrl: { type: String, required: true },
    sendUrl: { type: String, required: true },
    defaultEmail: { type: String, default: '' },
});

const search = ref('');
const selected = ref(props.mailables[0] ?? null);
const injected = ref(cloneInjected(selected.value));
const envelope = ref(cloneEnvelope(selected.value));
const previewKey = ref(0);
const previewWidth = ref('desktop');
const pane = ref('preview');
const sendModal = ref(false);
const copying = ref(false);

const previewWidths = {
    desktop: '100%',
    tablet: '600px',
    mobile: '375px',
};

const form = useForm({
    email: props.defaultEmail,
    mailable: selected.value?.class ?? '',
    values: {},
});

const injectedValues = computed(() => {
    return Object.fromEntries(
        injected.value
            .filter((param) => param.editable)
            .map((param) => [param.name, param.value]),
    );
});

const liveValues = ref({ ...injectedValues.value });

const filtered = computed(() => {
    const query = search.value.toLowerCase().trim();

    if (! query) {
        return props.mailables;
    }

    return props.mailables.filter((mailable) => {
        return [mailable.name, mailable.class, mailable.path, mailable.subject, mailable.from]
            .filter(Boolean)
            .some((value) => value.toLowerCase().includes(query));
    });
});

const previewSrc = computed(() => {
    if (! selected.value || selected.value.error) {
        return null;
    }

    const url = new URL(props.previewUrl, window.location.origin);
    url.searchParams.set('mailable', selected.value.class);
    url.searchParams.set('_', String(previewKey.value));
    appendValues(url);

    return url.toString();
});

const selectedAvatar = computed(() => {
    if (! selected.value) {
        return null;
    }

    return { name: selected.value.name };
});

const detailRows = computed(() => {
    const details = selected.value?.details;

    if (! details) {
        return [];
    }

    const rows = [
        { label: __('Class'), value: selected.value.class, mono: true },
        { label: __('File'), value: selected.value.path, mono: true },
        { label: __('Queued'), value: details.queued ? __('Yes') : __('No') },
        { label: __('Queue'), value: details.queue },
        { label: __('Template'), value: details.template?.engine },
        { label: __('View'), value: details.template?.view, mono: true },
        { label: __('View Path'), value: details.template?.path, mono: true },
        { label: __('Text View'), value: details.template?.text_view, mono: true },
        { label: __('Subject'), value: envelope.value.subject },
        { label: __('From'), value: envelope.value.from },
        {
            label: __('Attachments'),
            value: envelope.value.attachments
                ? __n(':count Attachment|:count Attachments', envelope.value.attachments)
                : null,
        },
    ];

    (details.events ?? []).forEach((item) => {
        rows.push({
            label: __('Event'),
            value: `${item.event} → ${item.listener}`,
            mono: true,
        });
    });

    (details.references ?? []).forEach((item) => {
        rows.push({
            label: item.kind || __('Referenced In'),
            value: item.file,
            mono: true,
        });
    });

    return rows.filter((row) => row.value !== null && row.value !== undefined && row.value !== '');
});

function cloneInjected(mailable) {
    return (mailable?.details?.constructor ?? []).map((param) => ({ ...param }));
}

function cloneEnvelope(mailable) {
    return {
        subject: mailable?.subject ?? null,
        from: mailable?.from ?? null,
        attachments: mailable?.attachments ?? 0,
    };
}

function appendValues(url) {
    Object.entries(liveValues.value).forEach(([name, value]) => {
        if (value === null || value === undefined) {
            return;
        }

        url.searchParams.set(`values[${name}]`, String(value));
    });
}

function syncLiveValuesFromInjected() {
    liveValues.value = { ...injectedValues.value };
}

function select(mailable) {
    refreshEnvelopeDebounced.cancel();
    selected.value = mailable;
    injected.value = cloneInjected(mailable);
    syncLiveValuesFromInjected();
    envelope.value = cloneEnvelope(mailable);
    form.mailable = mailable.class;
    previewKey.value++;
    pane.value = 'preview';
}

function send() {
    form.mailable = selected.value?.class ?? '';
    form.values = { ...injectedValues.value };
    form.post(props.sendUrl, {
        preserveScroll: true,
        onSuccess: () => {
            sendModal.value = false;
        },
    });
}

async function copyHtml() {
    if (! previewSrc.value) {
        return;
    }

    copying.value = true;

    try {
        const response = await fetch(previewSrc.value);
        const html = await response.text();
        await navigator.clipboard.writeText(html);
        Statamic.$toast.success(__('Copied to clipboard'));
    } catch {
        Statamic.$toast.error(__('Unable to copy to clipboard'));
    } finally {
        copying.value = false;
    }
}

async function refreshEnvelope() {
    if (! selected.value) {
        return;
    }

    const url = new URL(props.metaUrl, window.location.origin);
    url.searchParams.set('mailable', selected.value.class);
    appendValues(url);

    try {
        const response = await fetch(url);
        const data = await response.json();

        envelope.value = {
            subject: data.subject,
            from: data.from,
            attachments: data.attachments ?? 0,
        };

        if (selected.value) {
            selected.value.error = data.error;
        }
    } catch {
        //
    }
}

const refreshEnvelopeDebounced = debounce(() => {
    syncLiveValuesFromInjected();
    refreshEnvelope();
}, 300);

watch(injectedValues, () => {
    refreshEnvelopeDebounced();
});
</script>

<template>
    <div class="max-w-page mx-auto flex flex-col min-h-[calc(100vh-10rem)]">
        <Head :title="[__('mailables-viewer::messages.title'), __('Utilities')]" />

        <Header :title="__('mailables-viewer::messages.title')" icon="mail-inbox-content" />

        <EmptyStateMenu
            v-if="!mailables.length"
            :heading="__('mailables-viewer::messages.title')"
            :description="__('mailables-viewer::messages.empty')"
        >
            <EmptyStateItem
                icon="mail-inbox-content"
                :heading="__('No Mailables')"
                :description="__('mailables-viewer::messages.empty')"
            />
        </EmptyStateMenu>

        <Panel v-else class="flex-1 min-h-0 !mb-0">
            <Card inset class="h-[calc(100vh-14rem)] min-h-96 overflow-hidden">
                <SplitterGroup class="h-full">
                    <SplitterPanel :default-size="32" :min-size="22" class="flex flex-col h-full min-w-0">
                        <div class="p-4">
                            <Input
                                v-model="search"
                                icon="magnifying-glass"
                                :placeholder="__('Search')"
                                size="sm"
                                clearable
                            />
                        </div>

                        <div class="flex-1 overflow-y-auto px-4 pb-4 flex flex-col gap-2">
                            <p v-if="!filtered.length" class="px-1 py-6 text-sm text-gray-500">
                                {{ __('No results') }}
                            </p>

                            <button
                                v-for="mailable in filtered"
                                :key="mailable.class"
                                type="button"
                                class="flex flex-col items-start gap-1 w-full rounded-lg border border-gray-200 dark:border-gray-700 p-3 text-start transition-colors hover:bg-gray-50 dark:hover:bg-gray-800/60"
                                :class="{ 'bg-gray-100 dark:bg-gray-800 border-gray-300 dark:border-gray-600': selected?.class === mailable.class }"
                                @click="select(mailable)"
                            >
                                <div class="flex w-full items-center gap-2">
                                    <Text variant="strong" size="base" :text="mailable.name" class="truncate" />
                                    <Badge v-if="mailable.error" color="red" size="sm" :text="__('Error')" class="ms-auto shrink-0" />
                                </div>
                                <Text
                                    v-if="mailable.subject"
                                    size="sm"
                                    :text="mailable.subject"
                                    class="font-medium truncate w-full"
                                />
                                <Text
                                    v-if="mailable.path"
                                    size="xs"
                                    variant="subtle"
                                    :text="mailable.path"
                                    class="truncate w-full font-mono"
                                />
                            </button>
                        </div>
                    </SplitterPanel>

                    <SplitterResizeHandle class="w-px bg-gray-200 dark:bg-gray-700" />

                    <SplitterPanel :default-size="68" :min-size="36" class="flex flex-col h-full min-w-0">
                        <template v-if="selected">
                            <div class="flex items-center justify-between gap-2 p-2">
                                <ToggleGroup v-model="pane" size="sm">
                                    <ToggleItem value="preview" :label="__('Preview')" />
                                    <ToggleItem value="details" :label="__('Details')" />
                                </ToggleGroup>
                                <div class="flex items-center gap-2">
                                    <ToggleGroup v-if="pane === 'preview'" v-model="previewWidth" size="sm">
                                        <ToggleItem value="desktop" icon="computer-desktop" :label="__('Desktop')" />
                                        <ToggleItem value="tablet" icon="monitor" :label="__('Tablet')" />
                                        <ToggleItem value="mobile" icon="phone-telephone-call" :label="__('Mobile')" />
                                    </ToggleGroup>
                                    <Button
                                        size="sm"
                                        icon="share-link"
                                        :text="__('Copy HTML')"
                                        :loading="copying"
                                        :disabled="!!selected.error"
                                        @click="copyHtml"
                                    />
                                    <Button
                                        size="sm"
                                        variant="primary"
                                        icon="mail-send-email-attachment-document"
                                        :text="__('Send Test')"
                                        :disabled="!!selected.error"
                                        @click="sendModal = true"
                                    />
                                </div>
                            </div>

                            <div class="flex items-start gap-4 p-4 border-y border-gray-200 dark:border-gray-700">
                                <Avatar :user="selectedAvatar" class="size-10 rounded-full text-xs" />
                                <div class="min-w-0 grid gap-0.5 flex-1">
                                    <div class="flex items-center gap-2">
                                        <Heading size="base" :text="selected.name" />
                                        <Badge v-if="selected.error" color="red" size="sm" :text="__('Error')" />
                                        <Badge v-if="envelope.attachments" size="sm" :text="__n(':count Attachment|:count Attachments', envelope.attachments)" />
                                    </div>
                                    <Text v-if="envelope.subject" size="sm" :text="envelope.subject" class="truncate" />
                                    <Text v-if="envelope.from" size="sm" variant="subtle" class="truncate">
                                        {{ __('From') }}: {{ envelope.from }}
                                    </Text>
                                </div>
                            </div>

                            <div
                                v-if="pane === 'details'"
                                class="flex-1 min-h-0 overflow-auto p-4 flex flex-col gap-6"
                            >
                                <div v-if="injected.length" class="flex flex-col gap-2">
                                    <Heading size="base" :text="__('Injected Variables')" />
                                    <Table>
                                        <TableRow v-for="param in injected" :key="param.name">
                                            <TableCell class="w-40 align-middle">
                                                <Text variant="code" size="sm" :text="`$${param.name}`" />
                                            </TableCell>
                                            <TableCell>
                                                <Switch
                                                    v-if="param.editable && param.input === 'checkbox'"
                                                    v-model="param.value"
                                                    size="sm"
                                                />
                                                <Input
                                                    v-else-if="param.editable"
                                                    v-model="param.value"
                                                    :type="param.input"
                                                    size="sm"
                                                />
                                                <Text
                                                    v-else
                                                    variant="code"
                                                    size="sm"
                                                    :text="param.value"
                                                />
                                            </TableCell>
                                        </TableRow>
                                    </Table>
                                </div>

                                <div class="flex flex-col gap-4">
                                    <Heading v-if="injected.length" size="base" :text="__('Details')" />
                                    <Table>
                                        <TableRow v-for="row in detailRows" :key="row.label + String(row.value)">
                                            <TableCell class="w-40 align-top text-gray-500 dark:text-gray-400">
                                                {{ row.label }}
                                            </TableCell>
                                            <TableCell>
                                                <Text
                                                    :variant="row.mono ? 'code' : 'default'"
                                                    :size="row.mono ? 'sm' : 'base'"
                                                    :text="row.value"
                                                />
                                            </TableCell>
                                        </TableRow>
                                    </Table>
                                </div>
                            </div>

                            <div
                                v-else
                                class="flex-1 min-h-0 overflow-auto"
                                :class="previewWidth === 'desktop' ? 'bg-white dark:bg-gray-850' : 'bg-gray-100 dark:bg-gray-900 p-4'"
                            >
                                <div
                                    v-if="selected.error"
                                    class="max-w-lg mx-auto mt-8 text-center"
                                >
                                    <Heading size="lg" :text="__('Unable to preview this mailable')" />
                                    <Text variant="subtle" :text="selected.error" class="mt-2 block" />
                                </div>
                                <div
                                    v-else
                                    class="h-full mx-auto bg-white dark:bg-gray-850 transition-[width] duration-200"
                                    :class="{ 'shadow-sm': previewWidth !== 'desktop' }"
                                    :style="{ width: previewWidths[previewWidth], maxWidth: '100%' }"
                                >
                                    <iframe
                                        :key="previewKey"
                                        :src="previewSrc"
                                        sandbox="allow-popups allow-popups-to-escape-sandbox allow-same-origin"
                                        class="w-full h-full border-0"
                                        title="Mailable preview"
                                    />
                                </div>
                            </div>
                        </template>
                    </SplitterPanel>
                </SplitterGroup>
            </Card>
        </Panel>

        <ConfirmationModal
            v-model:open="sendModal"
            :title="__('Send Test Email')"
            :button-text="__('Send')"
            :busy="form.processing"
            @confirm="send"
        >
            <Field :label="__('Email')" :error="form.errors.email">
                <Input
                    v-model="form.email"
                    type="email"
                    name="email"
                />
            </Field>
            <ErrorMessage v-if="form.errors.mailable" :text="form.errors.mailable" class="mt-2" />
        </ConfirmationModal>
    </div>
</template>
