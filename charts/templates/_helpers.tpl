{{/* Common name/label helpers */}}
{{- define "moodle.name" -}}
{{- default .Chart.Name .Values.nameOverride | trunc 63 | trimSuffix "-" -}}
{{- end -}}

{{- define "moodle.fullname" -}}
{{- if .Values.fullnameOverride -}}
{{- .Values.fullnameOverride | trunc 63 | trimSuffix "-" -}}
{{- else -}}
{{- $name := default .Chart.Name .Values.nameOverride -}}
{{- if contains $name .Release.Name -}}
{{- .Release.Name | trunc 63 | trimSuffix "-" -}}
{{- else -}}
{{- printf "%s-%s" .Release.Name $name | trunc 63 | trimSuffix "-" -}}
{{- end -}}
{{- end -}}
{{- end -}}

{{- define "moodle.chart" -}}
{{- printf "%s-%s" .Chart.Name .Chart.Version | replace "+" "_" | trunc 63 | trimSuffix "-" -}}
{{- end -}}

{{- define "moodle.labels" -}}
helm.sh/chart: {{ include "moodle.chart" . }}
{{ include "moodle.selectorLabels" . }}
app.kubernetes.io/version: {{ .Chart.AppVersion | quote }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
{{- end -}}

{{- define "moodle.selectorLabels" -}}
app.kubernetes.io/name: {{ include "moodle.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
{{- end -}}

{{- define "moodle.serviceAccountName" -}}
{{- if .Values.serviceAccount.create -}}
{{- default (include "moodle.fullname" .) .Values.serviceAccount.name -}}
{{- else -}}
{{- default "default" .Values.serviceAccount.name -}}
{{- end -}}
{{- end -}}

{{- define "moodle.image" -}}
{{- printf "%s:%s" .Values.image.repository (.Values.image.tag | toString) -}}
{{- end -}}

{{/*
Secret name resolution. Precedence: per-group existingSecret > auth.existingSecret
(catch-all) > chart-managed Secret (inline auth.*, dev mode). The chart Secret is
only rendered when auth.existingSecret is empty (see secret.yaml).
*/}}
{{- define "moodle.defaultSecretName" -}}
{{- default (include "moodle.fullname" .) .Values.auth.existingSecret -}}
{{- end -}}
{{- define "moodle.dbSecretName" -}}
{{- default (include "moodle.defaultSecretName" .) .Values.externalDatabase.existingSecret -}}
{{- end -}}
{{- define "moodle.redisSecretName" -}}
{{- default (include "moodle.defaultSecretName" .) .Values.externalRedis.existingSecret -}}
{{- end -}}
{{- define "moodle.objectStoreSecretName" -}}
{{- default (include "moodle.defaultSecretName" .) .Values.objectfs.existingSecret -}}
{{- end -}}
{{- define "moodle.cdnSecretName" -}}
{{- $fallback := default (include "moodle.defaultSecretName" .) .Values.objectfs.existingSecret -}}
{{- default $fallback .Values.objectfs.cdn.existingSecret -}}
{{- end -}}
{{- define "moodle.adminSecretName" -}}
{{- include "moodle.defaultSecretName" . -}}
{{- end -}}

{{/*
Shared SECRET env (secretKeyRef). Used by web, cron, and both hook Jobs so a
freshly generated config.php is identical everywhere. Conditional on features.
*/}}
{{- define "moodle.secretEnv" -}}
- name: DB_PASS
  valueFrom:
    secretKeyRef:
      name: {{ include "moodle.dbSecretName" . }}
      key: db-password
{{- if .Values.externalDatabase.readReplica.enabled }}
- name: DB_PASS_REPLICA
  valueFrom:
    secretKeyRef:
      name: {{ include "moodle.dbSecretName" . }}
      key: db-password-replica
{{- end }}
- name: REDIS_PASSWORD
  valueFrom:
    secretKeyRef:
      name: {{ include "moodle.redisSecretName" . }}
      key: redis-password
- name: MOODLE_PASSWORD
  valueFrom:
    secretKeyRef:
      name: {{ include "moodle.adminSecretName" . }}
      key: admin-password
{{- if .Values.objectfs.enabled }}
- name: S3_ACCESS_KEY
  valueFrom:
    secretKeyRef:
      name: {{ include "moodle.objectStoreSecretName" . }}
      key: s3-access-key
- name: S3_SECRET_KEY
  valueFrom:
    secretKeyRef:
      name: {{ include "moodle.objectStoreSecretName" . }}
      key: s3-secret-key
{{- if .Values.objectfs.cdn.enabled }}
- name: CDN_SIGNING_KEY
  valueFrom:
    secretKeyRef:
      name: {{ include "moodle.cdnSecretName" . }}
      key: cdn-signing-key
{{- end }}
{{- end }}
{{- end -}}

{{/*
Shared volumes + volumeMounts (moodledata RWX, node-local localcache/tempdir,
scripts). Used by web, cron, and the configure Job (which needs moodledata).
*/}}
{{- define "moodle.volumes" -}}
- name: moodledata
  persistentVolumeClaim:
    claimName: {{ .Values.persistence.existingClaim | default (printf "%s-moodledata" (include "moodle.fullname" .)) }}
- name: localcache
  emptyDir:
    sizeLimit: {{ .Values.localcache.sizeLimit }}
- name: moodletemp
  emptyDir:
    sizeLimit: {{ .Values.tempdir.sizeLimit }}
- name: scripts
  configMap:
    name: {{ include "moodle.fullname" . }}-scripts
    defaultMode: 0755
{{- end -}}

{{- define "moodle.volumeMounts" -}}
- name: moodledata
  mountPath: /var/www/moodledata
- name: localcache
  mountPath: /var/www/localcache
- name: moodletemp
  mountPath: /var/www/moodletemp
- name: scripts
  mountPath: /scripts
{{- end -}}

{{/* Pod securityContext — image runs as uid/gid 65534 (nobody) */}}
{{- define "moodle.podSecurityContext" -}}
runAsNonRoot: true
runAsUser: 65534
runAsGroup: 65534
fsGroup: 65534
seccompProfile:
  type: RuntimeDefault
{{- end -}}

{{- define "moodle.containerSecurityContext" -}}
allowPrivilegeEscalation: false
{{- end -}}

{{/* Image pull secrets block */}}
{{- define "moodle.imagePullSecrets" -}}
{{- with .Values.image.pullSecrets }}
imagePullSecrets:
  {{- range . }}
  - name: {{ . }}
  {{- end }}
{{- end }}
{{- end -}}

{{/* wait-for-schema init container — gates web/cron until the migrate Job ran */}}
{{- define "moodle.waitForSchemaInit" -}}
- name: wait-for-schema
  image: {{ include "moodle.image" . }}
  imagePullPolicy: {{ .Values.image.pullPolicy }}
  securityContext:
    {{- include "moodle.containerSecurityContext" . | nindent 4 }}
  command: ["sh", "/scripts/wait-for-schema.sh"]
  envFrom:
    - configMapRef:
        name: {{ include "moodle.fullname" . }}-env
  env:
    {{- include "moodle.secretEnv" . | nindent 4 }}
  volumeMounts:
    - name: scripts
      mountPath: /scripts
{{- end -}}

{{/* DB-free liveness/startup probe (must hit 127.0.0.1 — nginx denies fpm-ping otherwise) */}}
{{- define "moodle.fpmPingExec" -}}
exec:
  command: ["sh", "-c", "curl -fsS http://127.0.0.1:8080/fpm-ping"]
{{- end -}}
