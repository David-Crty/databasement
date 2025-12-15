---
sidebar_position: 3
---

# Kubernetes + Helm

This guide will help you deploy DBBackup on Kubernetes using Helm.

## Prerequisites

- A Kubernetes cluster
- [Helm](https://helm.sh/docs/intro/install/) v3.x installed
- [kubectl](https://kubernetes.io/docs/tasks/tools/install-kubectl/) configured for your cluster

## Installation

### 1. Add the Helm Repository

```bash
helm repo add dbbackup https://davidcrty.github.io/dbbackup/charts
helm repo update
```

### 2. Create a Values File

```yaml title="values.yaml"
# Application configuration
app:
  name: DBBackup
  env: production
  debug: false
  url: https://backup.yourdomain.com
  key: base64:your-generated-key-here

# Database configuration
database:
  # Use an existing database
  external: true
  connection: mysql
  host: your-mysql-host.example.com
  port: 3306
  name: dbbackup
  username: dbbackup
  password: your-secure-password

  # Or deploy a MySQL container (not recommended for production)
  # external: false
  # mysql:
  #   enabled: true
  #   rootPassword: root-password
  #   password: dbbackup-password

# Ingress configuration
ingress:
  enabled: true
  className: nginx
  annotations:
    cert-manager.io/cluster-issuer: letsencrypt-prod
  hosts:
    - host: backup.yourdomain.com
      paths:
        - path: /
          pathType: Prefix
  tls:
    - secretName: dbbackup-tls
      hosts:
        - backup.yourdomain.com

# Resource limits
resources:
  limits:
    cpu: 500m
    memory: 512Mi
  requests:
    cpu: 100m
    memory: 256Mi

# Persistence
persistence:
  enabled: true
  size: 10Gi
  storageClass: ""  # Use default storage class

# Replica count
replicaCount: 1
```

### 3. Install the Chart

```bash
helm install dbbackup dbbackup/dbbackup -f values.yaml -n dbbackup --create-namespace
```

### 4. Verify the Deployment

```bash
kubectl get pods -n dbbackup
kubectl get svc -n dbbackup
kubectl get ingress -n dbbackup
```

## Generating the Application Key

Before deploying, generate an application key:

```bash
kubectl run --rm -it keygen --image=davidcrty/backup-manager:latest --restart=Never -- php artisan key:generate --show
```

Copy the output and use it as `app.key` in your values file.

## Configuration Options

### Full Values Reference

| Parameter | Description | Default |
|-----------|-------------|---------|
| `app.name` | Application name | `DBBackup` |
| `app.env` | Environment (`production`, `local`) | `production` |
| `app.debug` | Enable debug mode | `false` |
| `app.url` | Public URL | `http://localhost:8000` |
| `app.key` | Application encryption key | `""` (required) |
| `database.external` | Use external database | `true` |
| `database.connection` | Database type (`mysql`, `pgsql`, `sqlite`) | `mysql` |
| `database.host` | Database host | `""` |
| `database.port` | Database port | `3306` |
| `database.name` | Database name | `dbbackup` |
| `database.username` | Database username | `dbbackup` |
| `database.password` | Database password | `""` |
| `ingress.enabled` | Enable ingress | `false` |
| `ingress.className` | Ingress class | `""` |
| `persistence.enabled` | Enable persistence | `true` |
| `persistence.size` | PVC size | `10Gi` |
| `replicaCount` | Number of replicas | `1` |
| `resources.limits.cpu` | CPU limit | `500m` |
| `resources.limits.memory` | Memory limit | `512Mi` |

### Using External Secrets

For production, use Kubernetes secrets instead of plain values:

```yaml title="values.yaml"
app:
  existingSecret: dbbackup-secrets
  secretKeys:
    appKey: APP_KEY

database:
  existingSecret: dbbackup-db-secrets
  secretKeys:
    password: DB_PASSWORD
```

Create the secrets:

```bash
kubectl create secret generic dbbackup-secrets \
  --from-literal=APP_KEY='base64:your-key-here' \
  -n dbbackup

kubectl create secret generic dbbackup-db-secrets \
  --from-literal=DB_PASSWORD='your-db-password' \
  -n dbbackup
```

## High Availability

For high availability deployments:

```yaml title="values-ha.yaml"
replicaCount: 3

# Use external database for session/cache
# when running multiple replicas

podDisruptionBudget:
  enabled: true
  minAvailable: 1

affinity:
  podAntiAffinity:
    preferredDuringSchedulingIgnoredDuringExecution:
      - weight: 100
        podAffinityTerm:
          labelSelector:
            matchExpressions:
              - key: app.kubernetes.io/name
                operator: In
                values:
                  - dbbackup
          topologyKey: kubernetes.io/hostname
```

:::warning
When running multiple replicas, ensure your database can handle concurrent connections and that session storage is shared (database or Redis).
:::

## Upgrading

```bash
helm repo update
helm upgrade dbbackup dbbackup/dbbackup -f values.yaml -n dbbackup
```

## Uninstalling

```bash
helm uninstall dbbackup -n dbbackup
```

:::caution
This will not delete the PersistentVolumeClaim by default. To delete all data:
```bash
kubectl delete pvc -l app.kubernetes.io/name=dbbackup -n dbbackup
```
:::

## Troubleshooting

### Check Pod Logs

```bash
kubectl logs -f deployment/dbbackup -n dbbackup
```

### Check Pod Status

```bash
kubectl describe pod -l app.kubernetes.io/name=dbbackup -n dbbackup
```

### Access Pod Shell

```bash
kubectl exec -it deployment/dbbackup -n dbbackup -- sh
```

### Run Artisan Commands

```bash
kubectl exec deployment/dbbackup -n dbbackup -- php artisan migrate:status
kubectl exec deployment/dbbackup -n dbbackup -- php artisan queue:work --once
```
