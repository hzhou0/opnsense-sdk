// Package opnsensesdk provides a typed Go client for the OPNsense API.
//
// Authentication uses an API key/secret pair sent as HTTP Basic credentials
// (key = username, secret = password). Pass them via [Options] and they are
// attached to every request automatically.
//
// Example:
//
//	client, err := opnsensesdk.NewClient(opnsensesdk.Options{
//	    BaseURL:   "https://192.168.1.1",
//	    APIKey:    os.Getenv("OPNSENSE_KEY"),
//	    APISecret: os.Getenv("OPNSENSE_SECRET"),
//	})
//	if err != nil {
//	    log.Fatal(err)
//	}
//	resp, err := client.AuthGroupControllerGetAction(ctx, "some-uuid")
package opnsensesdk

import (
	"context"
	"encoding/base64"
	"fmt"
	"net/http"

	"github.com/hzhou0/opnsense-sdk/go-sdk/generated"
)

// Options configures the OPNsense API client.
type Options struct {
	// BaseURL of the OPNsense host, e.g. "https://192.168.1.1".
	// Must not be empty.
	BaseURL string

	// APIKey is sent as the HTTP Basic username.
	APIKey string

	// APISecret is sent as the HTTP Basic password.
	APISecret string

	// HTTPClient overrides the default *http.Client. Useful for custom TLS
	// config (e.g. trusting a self-signed OPNsense certificate).
	HTTPClient *http.Client
}

// NewClient creates a fully typed OPNsense API client.
// If both APIKey and APISecret are set, every request carries an
// Authorization: Basic header.
func NewClient(opts Options) (*generated.Client, error) {
	if opts.BaseURL == "" {
		return nil, fmt.Errorf("opnsensesdk: BaseURL must not be empty")
	}

	clientOpts := []generated.ClientOption{}

	if opts.HTTPClient != nil {
		clientOpts = append(clientOpts, generated.WithHTTPClient(opts.HTTPClient))
	}

	if opts.APIKey != "" && opts.APISecret != "" {
		encoded := base64.StdEncoding.EncodeToString([]byte(opts.APIKey + ":" + opts.APISecret))
		header := "Basic " + encoded
		clientOpts = append(clientOpts, generated.WithRequestEditorFn(
			func(_ context.Context, req *http.Request) error {
				req.Header.Set("Authorization", header)
				return nil
			},
		))
	}

	return generated.NewClient(opts.BaseURL, clientOpts...)
}
