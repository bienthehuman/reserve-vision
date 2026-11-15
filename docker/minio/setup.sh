#!/bin/sh
# One-shot MinIO setup (compose.prod.yaml, service minio-setup). Idempotent.
#
#   AWS_PHOTOS_BUCKET  public: anonymous GetObject only (no listing), served by Caddy at /media/photos
#   AWS_PROOFS_BUCKET  private: no anonymous access at all
#
# The app gets its own MinIO user (AWS_ACCESS_KEY_ID), limited to these two
# buckets. The root credentials stay with this script.
set -eu

mc alias set rv http://minio:9000 "$MINIO_ROOT_USER" "$MINIO_ROOT_PASSWORD" >/dev/null

mc mb --ignore-existing "rv/$AWS_PHOTOS_BUCKET"
mc mb --ignore-existing "rv/$AWS_PROOFS_BUCKET"

# Anonymous GetObject only. Not `mc anonymous set download`: that also allows
# ListBucket, which would let anyone enumerate every photo key.
cat > /tmp/photos-anonymous.json <<EOF
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Principal": {"AWS": ["*"]},
      "Action": ["s3:GetObject"],
      "Resource": ["arn:aws:s3:::$AWS_PHOTOS_BUCKET/*"]
    }
  ]
}
EOF
mc anonymous set-json /tmp/photos-anonymous.json "rv/$AWS_PHOTOS_BUCKET"
mc anonymous set none "rv/$AWS_PROOFS_BUCKET"

cat > /tmp/reservevision-app.json <<EOF
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": ["s3:GetBucketLocation", "s3:ListBucket"],
      "Resource": ["arn:aws:s3:::$AWS_PHOTOS_BUCKET", "arn:aws:s3:::$AWS_PROOFS_BUCKET"]
    },
    {
      "Effect": "Allow",
      "Action": ["s3:GetObject", "s3:PutObject", "s3:DeleteObject"],
      "Resource": ["arn:aws:s3:::$AWS_PHOTOS_BUCKET/*", "arn:aws:s3:::$AWS_PROOFS_BUCKET/*"]
    }
  ]
}
EOF

mc admin policy create rv reservevision-app /tmp/reservevision-app.json
mc admin user add rv "$AWS_ACCESS_KEY_ID" "$AWS_SECRET_ACCESS_KEY"
mc admin policy attach rv reservevision-app --user "$AWS_ACCESS_KEY_ID" 2>/dev/null \
    || echo "Policy reservevision-app already attached to $AWS_ACCESS_KEY_ID"

echo "MinIO ready: $AWS_PHOTOS_BUCKET (public read), $AWS_PROOFS_BUCKET (private)"
