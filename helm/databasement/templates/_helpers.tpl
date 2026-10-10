{{/*
Validate required values
*/}}
{{- define "databasement.validateValues" -}}
{{- if and .Values.app.enabled (not .Values.app.appKey.value) (not .Values.app.appKey.fromSecret) -}}
{{- fail "app.appKey.value is required. Generate one with: docker run --rm davidcrty/databasement:latest php artisan key:generate --show" -}}
{{- end -}}
{{- range $name, $agent := .Values.agents -}}
{{- if $agent.enabled -}}
{{- if not (regexMatch "^[a-z0-9]([-a-z0-9]{0,38}[a-z0-9])?$" $name) -}}
{{- fail (printf "agents.%s: agent names must be at most 40 lowercase letters, digits or hyphens, starting and ending with a letter or digit" $name) -}}
{{- end -}}
{{- if not $agent.url -}}
{{- fail (printf "agents.%s.url is required" $name) -}}
{{- end -}}
{{- $token := $agent.token | default dict -}}
{{- $fromSecret := $token.fromSecret | default dict -}}
{{- if or $fromSecret.secretName $fromSecret.secretKey -}}
{{- if not (and $fromSecret.secretName $fromSecret.secretKey) -}}
{{- fail (printf "agents.%s.token.fromSecret requires both secretName and secretKey" $name) -}}
{{- end -}}
{{- else if not $token.value -}}
{{- fail (printf "agents.%s.token.value or agents.%s.token.fromSecret is required" $name $name) -}}
{{- end -}}
{{- end -}}
{{- end -}}
{{- end -}}

{{/*
Expand the name of the chart.
*/}}
{{- define "databasement.name" -}}
{{- default .Chart.Name .Values.nameOverride | trunc 63 | trimSuffix "-" }}
{{- end }}

{{/*
Create a default fully qualified app name.
We truncate at 63 chars because some Kubernetes name fields are limited to this (by the DNS naming spec).
If release name contains chart name it will be used as a full name.
*/}}
{{- define "databasement.fullname" -}}
{{- if .Values.fullnameOverride }}
{{- .Values.fullnameOverride | trunc 63 | trimSuffix "-" }}
{{- else }}
{{- $name := default .Chart.Name .Values.nameOverride }}
{{- if contains $name .Release.Name }}
{{- .Release.Name | trunc 63 | trimSuffix "-" }}
{{- else }}
{{- printf "%s-%s" .Release.Name $name | trunc 63 | trimSuffix "-" }}
{{- end }}
{{- end }}
{{- end }}

{{/*
Create chart name and version as used by the chart label.
*/}}
{{- define "databasement.chart" -}}
{{- printf "%s-%s" .Chart.Name .Chart.Version | replace "+" "_" | trunc 63 | trimSuffix "-" }}
{{- end }}

{{/*
Common labels
*/}}
{{- define "databasement.labels" -}}
helm.sh/chart: {{ include "databasement.chart" . }}
{{ include "databasement.selectorLabels" . }}
{{- if .Chart.AppVersion }}
app.kubernetes.io/version: {{ .Chart.AppVersion | quote }}
{{- end }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
{{- end }}

{{/*
Selector labels
*/}}
{{- define "databasement.selectorLabels" -}}
app.kubernetes.io/name: {{ include "databasement.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
{{- end }}

{{/*
Create the name of the service account to use
*/}}
{{- define "databasement.serviceAccountName" -}}
{{- if .Values.serviceAccount.create }}
{{- default (include "databasement.fullname" .) .Values.serviceAccount.name }}
{{- else }}
{{- default "default" .Values.serviceAccount.name }}
{{- end }}
{{- end }}

{{/*
Common environment variables for app and worker containers
*/}}
{{- define "databasement.env" -}}
- name: SERVER_NAME
  value: ":{{ .Values.port }}"
- name: APP_NAME
  value: {{ .Values.app.name | quote }}
- name: APP_ENV
  value: {{ .Values.app.env | quote }}
- name: APP_DEBUG
  value: {{ .Values.app.debug | quote }}
- name: APP_URL
  value: {{ .Values.app.url | quote }}
{{- if .Values.app.appKey.fromSecret }}
- name: APP_KEY
  valueFrom:
    secretKeyRef:
      name: {{ .Values.app.appKey.fromSecret.secretName }}
      key: {{ .Values.app.appKey.fromSecret.secretKey }}
{{- else if .Values.app.appKey.value }}
- name: APP_KEY
  valueFrom:
    secretKeyRef:
      name: {{ include "databasement.fullname" . }}
      key: app-key
{{- end }}
- name: DB_CONNECTION
  value: {{ .Values.database.connection | quote }}
{{- if eq .Values.database.connection "sqlite" }}
- name: DB_DATABASE
  value: {{ .Values.database.sqlitePath | quote }}
{{- else }}
- name: DB_HOST
  value: {{ .Values.database.host | quote }}
- name: DB_PORT
  value: {{ .Values.database.port | quote }}
- name: DB_DATABASE
  value: {{ .Values.database.name | quote }}
- name: DB_USERNAME
  value: {{ .Values.database.username | quote }}
{{- if .Values.database.password.fromSecret }}
- name: DB_PASSWORD
  valueFrom:
    secretKeyRef:
      name: {{ .Values.database.password.fromSecret.secretName }}
      key: {{ .Values.database.password.fromSecret.secretKey }}
{{- else if .Values.database.password.value }}
- name: DB_PASSWORD
  valueFrom:
    secretKeyRef:
      name: {{ include "databasement.fullname" . }}
      key: db-password
{{- end }}
{{- end }}
- name: LOG_CHANNEL
  value: {{ .Values.logging.channel | quote }}
- name: LOG_LEVEL
  value: {{ .Values.logging.level | quote }}
- name: ENABLE_DATABASE_MIGRATION
  value: "false"
{{- range $key, $value := .Values.extraEnv }}
- name: {{ $key }}
  value: {{ $value | quote }}
{{- end }}
{{- end }}

{{/*
envFrom configuration for app and worker containers
*/}}
{{- define "databasement.envFrom" -}}
{{- with .Values.extraEnvFrom }}
{{ toYaml . }}
{{- end }}
{{- end }}
