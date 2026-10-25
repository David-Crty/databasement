/**
 * Site-wide announcement shown on the landing page and every docs page, or `null`
 * for none. Change `id` with the message: a reader who closed the banner sees
 * the next one. A security notice (tone 'danger') cannot be closed.
 *
 * Links are written relative to the version being read (no leading slash).
 */
export default {
    id: 'security-1-10-6',
    tone: 'danger',
    html: '<strong>Security update:</strong> a critical PHP vulnerability affects releases before 1.10.6. Update to <a href="https://github.com/David-Crty/databasement/releases/tag/v1.10.6">1.10.6</a> or later now: <a href="self-hosting/docker-compose/#updating">Docker Compose</a> · <a href="self-hosting/docker/#updating">Docker</a> · <a href="self-hosting/kubernetes-helm/#updating">Helm</a> · <a href="self-hosting/native-ubuntu/#updating-databasement">Ubuntu</a>',
};
