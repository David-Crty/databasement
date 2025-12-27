{{/*
Validate required values
*/}}
{{- define "databasement.validateValues" -}}
{{- if and (not .Values.app.appKey.value) (not .Values.app.appKey.fromSecret) -}}
{{- fail "app.appKey.value is required. Generate one with: docker run --rm davidcrty/databasement:latest php artisan key:generate --show" -}}
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
{{- define "databasement.envVars" -}}
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
{{- if .Values.database.existingSecret }}
- name: DB_PASSWORD
  valueFrom:
    secretKeyRef:
      name: {{ .Values.database.existingSecret }}
      key: {{ .Values.database.secretKeys.password }}
{{- else if .Values.database.password }}
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
{{- range $key, $value := .Values.env }}
- name: {{ $key }}
  value: {{ $value | quote }}
{{- end }}
{{- end }}
