<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'

import { formatDateTimeOr } from '@/features/admin/format'
import type { MailPreview, MailTemplate } from '@/features/admin/mailSchemas'
import { adminMailService } from '@/features/admin/mailService'
import { isEdited, placeHints } from '@/features/admin/mailState'
import { describeAdminFailure } from '@/features/admin/refusals'

/**
 * Administration → Mail templates: the wording people actually receive.
 *
 * Two things about this screen are worth stating out loud, because both look like
 * omissions until you know why.
 *
 * **It is not a template editor in the usual sense.** There is no way to add a template
 * and no way to delete one: a template corresponds to a place in the code that decides
 * to send mail. What can be changed is wording, and only wording, using places from a
 * closed list the server sends. Everything else typed into the body — `{% if %}`, a PHP
 * tag, anything that resembles a template engine — arrives in the recipient's mailbox as
 * the characters that were typed. The screen says that, because otherwise the first
 * person to try it will assume it is broken.
 *
 * **The preview is rendered by the server**, one request per press, rather than by
 * substituting strings here. A preview drawn in the browser would be a second
 * implementation of the one rule whose whole value is that there is exactly one — and it
 * would cheerfully show wording the server is about to refuse. It doubles as validation:
 * whatever the preview refuses, saving refuses too, with the same sentence.
 *
 * The preview and the test send both use **sample values**. A preview built from a real
 * invitation would put a working token on the screen of whoever opened the editor, and
 * the test send would mail it to them.
 */
const templates = ref<MailTemplate[]>([])
const busy = ref(false)
const problem = ref<string | null>(null)

/** The key being edited. Null while the list is still loading or empty. */
const openKey = ref<string | null>(null)

const subject = ref('')
const body = ref('')

/** Set only by a press of „Podgląd”: what the server made of the wording on screen. */
const preview = ref<MailPreview | null>(null)
const previewBusy = ref(false)

/** Refusal of a save or a preview — shown next to the fields, not as a page-level error. */
const refusal = ref<string | null>(null)
const saved = ref<string | null>(null)
const testResult = ref<string | null>(null)

const open = computed(() => templates.value.find((one) => one.key === openKey.value) ?? null)

const edited = computed(() =>
  open.value === null ? false : isEdited(open.value, subject.value, body.value),
)

const hints = computed(() => (open.value === null ? [] : placeHints(open.value)))

/**
 * Places used in the subject that may only be used in the body.
 *
 * Checked here as well as on the server, and the duplication is deliberate: this one is
 * a hint shown while typing, the server's is the rule. A person who has just pasted
 * `{{ link }}` into the subject learns it immediately rather than on save — and if these
 * two ever disagree, the save is what decides.
 */
const sensitiveInSubject = computed(() => {
  if (open.value === null) {
    return []
  }

  return open.value.sensitive.filter((name) =>
    new RegExp(`\\{\\{\\s*${name}\\s*\\}\\}`).test(subject.value),
  )
})

async function load(): Promise<void> {
  busy.value = true
  problem.value = null

  try {
    templates.value = await adminMailService.templates()

    if (templates.value.length > 0) {
      choose(templates.value[0]!)
    }
  } catch (cause) {
    problem.value = describeAdminFailure(cause, 'Nie udało się wczytać szablonów maili.')
  } finally {
    busy.value = false
  }
}

function choose(template: MailTemplate): void {
  openKey.value = template.key
  subject.value = template.subject
  body.value = template.body
  // The saved wording's own preview, so the panel is never blank — replaced the moment
  // somebody presses „Podgląd”.
  preview.value = template.preview
  refusal.value = null
  saved.value = null
  testResult.value = null
}

function revert(): void {
  if (open.value !== null) {
    choose(open.value)
  }
}

async function showPreview(): Promise<void> {
  if (open.value === null) {
    return
  }

  previewBusy.value = true
  refusal.value = null
  saved.value = null

  try {
    preview.value = await adminMailService.preview(open.value.key, subject.value, body.value)
  } catch (cause) {
    // A 422 here is the same sentence saving would give, which is the point: the preview
    // button is also the "is this valid" button.
    refusal.value = describeAdminFailure(cause, 'Nie udało się złożyć podglądu.')
  } finally {
    previewBusy.value = false
  }
}

async function save(): Promise<void> {
  if (open.value === null) {
    return
  }

  busy.value = true
  refusal.value = null
  saved.value = null
  testResult.value = null

  try {
    const updated = await adminMailService.save(open.value.key, subject.value, body.value)

    templates.value = templates.value.map((one) => (one.key === updated.key ? updated : one))
    choose(updated)
    saved.value = 'Zapisane. Następny mail tego rodzaju pójdzie w nowej treści.'
  } catch (cause) {
    refusal.value = describeAdminFailure(cause, 'Nie udało się zapisać szablonu.')
  } finally {
    busy.value = false
  }
}

async function sendTest(): Promise<void> {
  if (open.value === null) {
    return
  }

  busy.value = true
  refusal.value = null
  testResult.value = null

  try {
    const answer = await adminMailService.testSend(open.value.key)

    testResult.value = `${answer.message} Adresat: ${answer.recipient}.`
  } catch (cause) {
    refusal.value = describeAdminFailure(cause, 'Nie udało się wysłać wiadomości próbnej.')
  } finally {
    busy.value = false
  }
}

onMounted(() => void load())
</script>

<template>
  <div class="max-w-5xl space-y-4">
    <div>
      <h1 class="text-xl font-semibold">Szablony maili</h1>
      <p class="text-sm text-muted">
        Treść wiadomości, które wysyła ta instancja. Zmiana obowiązuje od następnego maila
        danego rodzaju i idzie do dziennika audytu.
      </p>
      <p class="mt-1 text-sm text-muted">
        <strong>Szablonów nie dodaje się i nie usuwa</strong> — każdy odpowiada miejscu w
        kodzie, które wysyła maila. Zmieniać można treść, wstawiając miejsca z listy niżej.
        Wszystko inne trafia do skrzynki adresata <strong>dosłownie</strong>: to nie jest
        silnik szablonów, więc <code>{{ '{% if %}' }}</code> ani kod nic tu nie robią.
      </p>
    </div>

    <UAlert
      v-if="problem !== null"
      color="error"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      :description="problem"
    />

    <p v-else-if="busy && templates.length === 0" class="text-sm text-muted">Wczytuję…</p>

    <template v-else-if="open !== null">
      <!-- Przełącznik szablonów pokazuje się dopiero od dwóch: przy jednym byłby
           elementem interfejsu bez żadnego wyboru. -->
      <div v-if="templates.length > 1" class="flex flex-wrap gap-2">
        <UButton
          v-for="template in templates"
          :key="template.key"
          size="sm"
          :variant="template.key === openKey ? 'solid' : 'subtle'"
          color="neutral"
          @click="choose(template)"
        >
          {{ template.label }}
        </UButton>
      </div>

      <UCard>
        <template #header>
          <div>
            <p class="font-medium">{{ open.label }}</p>
            <p class="text-sm text-muted">{{ open.whenSent }}</p>
            <p class="mt-1 text-xs text-muted">
              Ostatnia zmiana: {{ formatDateTimeOr(open.updatedAt, 'nieznana') }}
              <template v-if="open.updatedByEmail !== null"> · {{ open.updatedByEmail }}</template>
              <template v-else> · treść domyślna, nikt jej jeszcze nie zmieniał</template>
            </p>
          </div>
        </template>

        <div class="space-y-4">
          <UFormField label="Temat" help="W temacie nie wolno używać miejsc oznaczonych „tylko treść”.">
            <UInput v-model="subject" class="w-full" />
          </UFormField>

          <UAlert
            v-if="sensitiveInSubject.length > 0"
            color="warning"
            variant="subtle"
            icon="i-lucide-triangle-alert"
            :description="`W temacie jest ${sensitiveInSubject
              .map((name) => `{{ ${name} }}`)
              .join(', ')}. Temat zapisuje się w dzienniku maili, więc zapis zostanie odrzucony.`"
          />

          <UFormField label="Treść">
            <UTextarea v-model="body" :rows="14" class="w-full font-mono text-sm" />
          </UFormField>

          <div>
            <p class="text-sm font-medium">Miejsca dozwolone w tym szablonie</p>
            <ul class="mt-1 space-y-1 text-sm">
              <li v-for="hint in hints" :key="hint.name" class="flex flex-wrap items-baseline gap-2">
                <code class="text-xs">{{ hint.token }}</code>
                <span class="text-muted">→ {{ hint.sample }}</span>
                <UBadge v-if="hint.bodyOnly" color="warning" variant="subtle" size="sm">
                  tylko treść
                </UBadge>
              </li>
            </ul>
          </div>
        </div>

        <template #footer>
          <div class="flex flex-wrap items-center gap-2">
            <UButton :loading="busy" :disabled="!edited" @click="save">Zapisz</UButton>
            <UButton variant="subtle" color="neutral" :disabled="!edited" @click="revert">
              Przywróć zapisane
            </UButton>
            <UButton variant="subtle" :loading="previewBusy" @click="showPreview">Podgląd</UButton>
            <UButton variant="ghost" color="neutral" :loading="busy" @click="sendTest">
              Wyślij próbnie do siebie
            </UButton>
            <span v-if="edited" class="text-xs text-muted">niezapisane zmiany</span>
          </div>
        </template>
      </UCard>

      <UAlert
        v-if="refusal !== null"
        color="error"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        :description="refusal"
      />
      <UAlert
        v-if="saved !== null"
        color="success"
        variant="subtle"
        icon="i-lucide-check"
        :description="saved"
      />
      <UAlert
        v-if="testResult !== null"
        color="info"
        variant="subtle"
        icon="i-lucide-mail"
        :description="testResult"
      />

      <UCard v-if="preview !== null">
        <template #header>
          <p class="font-medium">Podgląd na wartościach przykładowych</p>
          <p class="text-sm text-muted">
            Miejsca wypełnione przykładami, nigdy prawdziwym tokenem — link z podglądu
            niczego nie otwiera.
          </p>
        </template>

        <p class="text-sm">
          <span class="text-muted">Temat:</span> <strong>{{ preview.subject }}</strong>
        </p>
        <!-- Treść monospace i bez interpretacji: mail jest tekstem, a podgląd ma pokazywać
             dokładnie to, co dostanie adresat. -->
        <pre
          class="mt-2 max-h-96 overflow-auto rounded bg-elevated p-3 text-sm whitespace-pre-wrap break-words"
          >{{ preview.body }}</pre
        >
      </UCard>
    </template>

    <UCard v-else>
      <p class="font-medium">Nie ma żadnego szablonu.</p>
      <p class="mt-1 text-sm text-muted">
        Treści domyślne wgrywa migracja, więc pusta lista znaczy, że migracje nie
        przeszły do końca.
      </p>
    </UCard>
  </div>
</template>
