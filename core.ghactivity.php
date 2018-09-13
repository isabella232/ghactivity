<?php
/**
 * GHActivity calls to GitHub API
 *
 * https://developer.github.com/v3/
 *
 * @since 1.0
 */

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

/**
 * GitHub API Calls
 *
 * @since 1.0
 */
class GHActivity_Calls {

	function __construct() {
		add_action( 'ghactivity_publish', array( $this, 'publish_event' ) );
		if ( ! wp_next_scheduled( 'ghactivity_publish' ) ) {
			wp_schedule_event( time(), 'hourly', 'ghactivity_publish' );
		}
	}

	/**
	 * Get option saved in the plugin's settings screen.
	 *
	 * @since 1.0
	 *
	 * @param string $name Option name.
	 *
	 * @return string $str Specific option.
	 */
	private function get_option( $name ) {
		$options = get_option( 'ghactivity' );

		if ( isset( $options[ $name ] ) ) {
			return $options[ $name ];
		} else {
			return '';
		}
	}

	/**
	 * Remote call utility function for GitHub.
	 *
	 * @since 2.0.0
	 *
	 * @param string $query_url GitHub API URL to hit.
	 *
	 * @return array $response_body Response body for each call.
	 */
	private function get_github_data( $query_url ) {
		$response_body = array();

		$data = wp_remote_get( esc_url_raw( $query_url ) );

		if (
			is_wp_error( $data )
			|| 200 != $data['response']['code']
			|| empty( $data['body'] )
		) {
			return $response_body;
		}

		$response_body = json_decode( $data['body'] );

		return $response_body;
	}

	/**
	 * Remote call to get data from GitHub's API.
	 *
	 * @since 1.0
	 *
	 * @return null|array
	 */
	private function get_github_activity() {

		$response_body = array();

		/**
		 * Create an array of usernames.
		 * I try to account for single usernames, comma separated lists, space separated lists, and comma + space lists.
		 */
		$usernames = array_filter( preg_split( '/[,\s]+/', $this->get_option( 'username' ) ) );

		// Loop through that array and make a request to the GitHub API for each person.
		foreach ( $usernames as $username ) {
			$query_url = sprintf(
				'https://api.github.com/users/%1$s/events?access_token=%2$s',
				$username,
				$this->get_option( 'access_token' )
			);
			$single_response_body = $this->get_github_data( $query_url );

			$response_body = array_merge( $single_response_body, $response_body );
		}

		// If we have repos to watch, let's get data for them.
		$repos_to_monitor = $this->get_monitored_repos( 'names' );
		if ( ! empty( $repos_to_monitor ) ) {
			foreach ( $repos_to_monitor as $repo ) {
				$repo_activity = $this->get_repo_activity( $repo );
				// If we got data from those repos, add it to the existing list of events.
				if ( isset( $repo_activity ) && is_array( $repo_activity ) ) {
					$response_body = array_merge( $repo_activity, $response_body );
				}
			}
		}

		// Finally return the response.
		return $response_body;
	}

	/**
	 * Remote call to get data for a specific repo
	 *
	 * @since 1.6.0
	 *
	 * @param string $repo_name Name of the repo we want data from.
	 *
	 * @return array
	 */
	private function get_repo_activity( $repo_name = '' ) {
		if ( empty( $repo_name ) ) {
			return array();
		}

		$query_url = sprintf(
			'https://api.github.com/repos/%1$s/events?access_token=%2$s',
			esc_html( $repo_name ),
			$this->get_option( 'access_token' )
		);

		return $this->get_github_data( $query_url );
	}

	/**
	 * Remote call to get information about a specific GitHub user.
	 *
	 * @since 1.6.0
	 *
	 * @param string $gh_username GitHub username.
	 *
	 * @return array $gh_user_details Details about a GitHub user.
	 */
	private function get_person_details( $gh_username = '' ) {
		if ( empty( $gh_username ) ) {
			return array();
		}

		// Let's get some info from GitHub.
		$query_url = sprintf(
			'https://api.github.com/users/%1$s?access_token=%2$s',
			$gh_username,
			$this->get_option( 'access_token' )
		);
		$person_info_body = $this->get_github_data( $query_url );

		/**
		 * Let's build a name based on the name field.
		 * If it is not defined, fall back to username.
		 */
		if ( ! empty( $person_info_body->name ) ) {
			$nicename = $person_info_body->name;
		} else {
			$nicename = $person_info_body->login;
		}

		// Build the array of data we will save.
		$gh_user_details = array(
			'name'        => esc_html( $nicename ),
			'avatar_url'  => esc_url( $person_info_body->avatar_url ),
			'bio'         => esc_html( $person_info_body->bio ),
			'is_employee' => (bool) $this->is_company_member( $person_info_body->login ),
		);

		return $gh_user_details;
	}

	/**
	 * Does a GitHub user belong to a specific organization?
	 *
	 * @since 2.0.0
	 *
	 * @param string $gh_username GitHub username.
	 *
	 * @return bool false Does the person belongs to a specific organization? Default to false.
	 */
	private function is_company_member( $gh_username ) {
		if ( empty( $gh_username ) ) {
			return false;
		}

		// Let's get some info from GitHub.
		$query_url = sprintf(
			'https://api.github.com/users/%1$s/orgs?access_token=%2$s',
			$gh_username,
			$this->get_option( 'access_token' )
		);
		$person_orgs_body = $this->get_github_data( $query_url );

		/**
		 * Define your own organization name here.
		 * It will allow you to filter people that belong to your organization.
		 *
		 * @since 2.0.0
		 *
		 * @param null|string $org_name Name of your organization, as it appears in the organization you've set up on GitHub.
		 */
		$org_name = apply_filters( 'ghactivity_organization_slug', null );

		/**
		 * Does the list of organizations include the one you've defined in the filter?
		 * If so, return true.
		 */
		if (
			! empty( $person_orgs_body )
			&& ! empty( $org_name )
		) {
			foreach ( $person_orgs_body as $org => $org_detail ) {
				if ( $org_detail->login === $org_name ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Generate array of labels from multidimensional array.
	 * Utility function.
	 *
	 * @since 2.0.0
	 *
	 * @param array $labels Array of labels and their details as provided by GitHub.
	 *
	 * @return array $label_names Array of label names.
	 */
	private function get_label_names( $labels = array() ) {
		$label_names = array();

		if ( ! empty( $labels ) ) {
			foreach ( $labels as $label ) {
				$label_names[] = esc_html( $label->name );
			}
		}

		return $label_names;
	}

	/**
	 * Get an array of repos we want to follow a bit more closely.
	 * For those repos we will log activity from everyone,
	 * not just from the usernames set in the plugin options.
	 *
	 * We will select all repos from the ghactivity_repo taxonomy,
	 * and monitor all those that have the `full_reporting` term meta set to true.
	 *
	 * @since 2.0.0
	 *
	 * @param string $fields Type of info to return. Accept full or names. Default to full.
	 *
	 * @return WP_Error|array $repos_to_monitor Array of repos to monitor.
	 */
	private function get_monitored_repos( $fields = 'full' ) {
		$repos_query_args = array(
			'taxonomy'   => 'ghactivity_repo',
			'hide_empty' => false,
			'number'     => 10, // Just to make sure we don't get rate-limited by GH.
			'fields'     => 'id=>name',
			'meta_query' => array(
				array(
					'key'     => 'full_reporting',
					'value'   => true,
					'compare' => '=',
				),
			),
		);
		$repos_to_monitor = get_terms( $repos_query_args );

		if ( 'full' === $fields ) {
			return $repos_to_monitor;
		} else {
			$repo_names = array();
			if (
				! is_wp_error( $repos_to_monitor )
				&& is_array( $repos_to_monitor )
				&& ! empty( $repos_to_monitor )
			) {
				foreach ( $repos_to_monitor as $id => $name ) {
					$repo_names[] = $name;
				}
			}
			return $repo_names;
		}
	}

	/**
	 * Get an event type to use as a taxonomy, and in the post content.
	 *
	 * Starts from data collected with GitHub API, and displays a nice event type instead.
	 *
	 * @see https://developer.github.com/v3/activity/events/types/
	 *
	 * @since 1.0
	 *
	 * @param string $event_type Event type returned by GitHub API.
	 * @param string $action Action taken during event, as returned by GitHub API.
	 *
	 * @return string $ghactivity_event_type Event type displayed in ghactivity_event_type taxonomy.
	 */
	private function get_event_type( $event_type, $action ) {
		if ( 'IssuesEvent' == $event_type ) {
			if ( 'closed' == $action ) {
				$ghactivity_event_type = __( 'Issue Closed', 'ghactivity' );
			} elseif ( 'opened' == $action ) {
				$ghactivity_event_type = __( 'Issue Opened', 'ghactivity' );
			} else {
				$ghactivity_event_type = __( 'Issue touched', 'ghactivity' );
			}
		} elseif ( 'PullRequestEvent' == $event_type ) {
			if ( 'closed' == $action ) {
				$ghactivity_event_type = __( 'PR Closed', 'ghactivity' );
			} elseif ( 'opened' == $action ) {
				$ghactivity_event_type = __( 'PR Opened', 'ghactivity' );
			} else {
				$ghactivity_event_type = __( 'PR touched', 'ghactivity' );
			}
		} elseif ( 'IssueCommentEvent' == $event_type || 'CommitCommentEvent' == $event_type ) {
			$ghactivity_event_type = __( 'Comment', 'ghactivity' );
		} elseif ( 'PullRequestReviewCommentEvent' == $event_type ) {
			$ghactivity_event_type = __( 'Reviewed a PR', 'ghactivity' );
		} elseif ( 'PushEvent' == $event_type ) {
			$ghactivity_event_type = __( 'Pushed a branch', 'ghactivity' );
		} elseif ( 'CreateEvent' == $event_type ) {
			$ghactivity_event_type = __( 'Created a tag', 'ghactivity' );
		} elseif ( 'ReleaseEvent' == $event_type ) {
			$ghactivity_event_type = __( 'Created a release', 'ghactivity' );
		} elseif ( 'DeleteEvent' == $event_type ) {
			$ghactivity_event_type = __( 'Deleted a branch' );
		} elseif ( 'GollumEvent' == $event_type ) {
			$ghactivity_event_type = __( 'Edited a Wiki page' );
		} elseif ( 'ForkEvent' == $event_type ) {
			$ghactivity_event_type = __( 'Forked a repo' );
		} else {
			$ghactivity_event_type = __( 'Did something', 'ghactivity' );
		}

		/**
		 * Filter Event Type creation.
		 *
		 * Allows you to define your own Event types.
		 *
		 * @since 1.3
		 *
		 * @param string $ghactivity_event_type Clean event type returned after function.
		 * @param string $event_type Event type returned by GitHub API.
		 * @param string $action Action taken during event, as returned by GitHub API.
		 */
		$ghactivity_event_type = apply_filters( 'ghactivity_event_type', $ghactivity_event_type, $event_type, $action );

		return $ghactivity_event_type;
	}

	/**
	 * Get HTML link matching the event.
	 *
	 * @since 1.5.0
	 *
	 * @param object $event Event information returned by GitHub API.
	 * @param string $action Action taken during event, as returned by GitHub API.
	 *
	 * @return string $link_html HTML link matching the action recorded by GitHub.
	 */
	private function get_event_link( $event, $action = '' ) {
		if (
			empty( $event )
			|| empty( $event->type )
		) {
			return '';
		}

		if ( 'IssuesEvent' == $event->type ) {
			$link = $event->payload->issue->html_url;
		} elseif ( 'PullRequestEvent' == $event->type ) {
			$link = $event->payload->pull_request->html_url;
		} elseif (
			'IssueCommentEvent' == $event->type
			|| 'CommitCommentEvent' == $event->type
			|| 'PullRequestReviewCommentEvent' == $event->type
		) {
			$link = $event->payload->comment->html_url;
		} elseif ( 'PushEvent' == $event->type ) {
			$link = sprintf(
				'https://github.com/%1$s/commits/%2$s',
				esc_attr( $event->repo->name ),
				esc_attr( $event->payload->head )
			);
		} elseif ( 'CreateEvent' == $event->type ) {
			$link = sprintf(
				'https://github.com/%1$s/tree/%2$s',
				esc_attr( $event->repo->name ),
				esc_attr( $event->payload->ref )
			);
		} elseif ( 'ReleaseEvent' == $event->type ) {
			$link = $event->payload->release->html_url;
		} elseif ( 'ForkEvent' == $event->type ) {
			$link = $event->payload->forkee->html_url;
		} else {
			$link = '';
		}

		if ( ! empty( $link ) ) {
			$link_html = sprintf(
				'<a href="%2$s">%1$s</a>',
				esc_html( $this->get_event_type( $event->type, $action ) ),
				esc_url( $link )
			);
		} else {
			$link_html = esc_html( $this->get_event_type( $event->type, $action ) );
		}

		/**
		 * Filter Event HTML link.
		 *
		 * @since 1.5.0
		 *
		 * @param string $link_html HTML tag including the link to the GitHub event.
		 * @param object $event Event information returned by GitHub API.
		 * @param string $action Action taken during event, as returned by GitHub API.
		 */
		return apply_filters( 'ghactivity_event_link_html', $link_html, $event, $action );
	}

	/**
	 * Publish GitHub Event.
	 *
	 * @since 1.0
	 */
	public function publish_event() {
		// Avoid timeouts during the data import process.
		set_time_limit( 0 );

		$github_events = $this->get_github_activity();

		/**
		 * Only go through the event list if we have valid event array.
		 */
		if ( isset( $github_events ) && is_array( $github_events ) ) {

			foreach ( $github_events as $event ) {
				// Let's not keep private events if you don't want to save them.
				if (
					false == $event->public
					&& true != $this->get_option( 'display_private' )
				) {
					continue;
				}

				// If no post exists with that ID, let's go on and publish a post.
				if ( is_null( get_page_by_title( $event->id, OBJECT, 'ghactivity_event' ) ) ) {
					// Store the number of commits attached to the event in post meta.
					if ( 'PushEvent' == $event->type ) {
						$meta = array( '_github_commits' => absint( $event->payload->distinct_size ) );
					} else {
						$meta = false;
					}

					// Avoid errors when no action is attached to the event.
					if ( isset( $event->payload->action ) ) {
						$action = $event->payload->action;
					} else {
						$action = '';
					}

					// Create taxonomies.
					$taxonomies = array(
						'ghactivity_event_type' => esc_html( $this->get_event_type( $event->type, $action ) ),
						'ghactivity_repo'       => esc_html( $event->repo->name ),
						'ghactivity_actor'      => esc_html( $event->actor->display_login ),
					);

					// Build Post Content.
					$post_content = $this->get_event_link( $event, $action );

					// Mention the number of commits if there are any.
					if ( $meta ) {
						$post_content .= sprintf(
							__( ', including %1$s commits.', 'ghactivity' ),
							$meta['_github_commits']
						);
					}

					/**
					 * Small interlude: let's record info in the ghactivity_issue CPT
					 * if the event is about an issue or PR.
					 */
					if (
						(
							'PullRequestEvent' === $event->type
							|| 'IssuesEvent' === $event->type
							|| 'IssueCommentEvent' === $event->type
							|| 'PullRequestReviewCommentEvent' === $event->type
						)
						&& (
							! empty( $event->payload->issue )
							|| ! empty( $event->payload->pull_request )
						)
						&& (
							in_array(
								$event->repo->name,
								/**
								 * Allow site owners to only log issues for specific repos.
								 *
								 * @since 2.0.0
								 *
								 * @param array $repos Array of repos for which we want to monitor events.
								 */
								apply_filters( 'ghactivity_issues_repo_to_monitor', $this->get_monitored_repos( 'names' ) )
							)
						)
					) {
						// Is it an issue or a PR?
						if ( ! empty( $event->payload->pull_request ) ) {
							$issue_type = 'pull_request';
							$created_at = $event->payload->pull_request->created_at;
							$state      = $event->payload->pull_request->state;
							$title      = esc_html( $event->payload->pull_request->title );
							$labels     = ( isset( $event->payload->pull_request->labels ) ? $this->get_label_names( $event->payload->pull_request->labels ) : array() );
							$number     = $event->payload->pull_request->number;
						} else {
							$issue_type = 'issue';
							$created_at = $event->payload->issue->created_at;
							$state      = $event->payload->issue->state;
							$title      = esc_html( $event->payload->issue->title );
							$labels     = ( isset( $event->payload->issue->labels ) ? $this->get_label_names( $event->payload->issue->labels ) : array() );
							$number     = $event->payload->issue->number;
						}

						/**
						 * Specify a creator when an issue or PR is opened.
						 * Favorize display_login when possible.
						 */
						if ( 'opened' === $event->payload->action ) {
							$creator = esc_html( $event->actor->display_login );
						} elseif ( ! empty( $event->payload->pull_request ) ) {
							$creator = esc_html( $event->payload->pull_request->user->login );
						} elseif ( ! empty( $event->payload->issue ) ) {
							$creator = esc_html( $event->payload->issue->user->login );
						} else {
							$creator = '';
						}

						// Record event.
						$issue_details = array(
							'type'       => $issue_type,
							'event_type' => $taxonomies['ghactivity_event_type'],
							'created_at' => $created_at,
							'number'     => ( ! empty( $number ) ? absint( $number ) : 0 ),
							'repo_name'  => esc_html( $event->repo->name ),
							'state'      => ( isset( $state ) ? esc_html( $state ) : 'open' ),
							'title'      => $title,
							'comments'   => ( isset( $event->payload->comments ) ? $event->payload->comments : 0 ),
							'creator'    => $creator,
							'labels'     => $labels,
						);
						$this->record_issue_details( $issue_details );
					}

					// Finally, publish our event.
					$event_args = array(
						'post_title'   => $event->id,
						'post_type'    => 'ghactivity_event',
						'post_status'  => 'publish',
						'post_date'    => $event->created_at,
						'tax_input'    => $taxonomies,
						'meta_input'   => $meta,
						'post_content' => $post_content,
					);
					$post_id = wp_insert_post( $event_args );

					/**
					 * Establish the relationship between terms and taxonomies.
					 */
					foreach ( $taxonomies as $taxonomy => $value ) {
						$term_taxonomy_ids = wp_set_object_terms( $post_id, $value, $taxonomy, true );

						/**
						 * Since wp_set_object_terms returned an array of term_taxonomy_ids after running,
						 * we can use it to add more info to each term.
						 * From Term taxonomy IDs, we'll get term IDs.
						 * Then from there, we'l update the term and add a description and additional information if needed.
						 */
						if ( is_array( $term_taxonomy_ids ) && ! empty( $term_taxonomy_ids ) ) {
							foreach ( $term_taxonomy_ids as $term_taxonomy_id ) {
								/**
								 * Let's search for people without info attached to their profile.
								 * We'll try to get that info from GitHub.
								 */
								$term_id_object = get_term_by( 'term_taxonomy_id', $term_taxonomy_id, 'ghactivity_actor', ARRAY_A );
								$term_id = (int) $term_id_object['term_id'];
								if (
									is_array( $term_id_object )
									&& 'ghactivity_actor' === $term_id_object['taxonomy']
									//&& empty( get_term_meta( $term_id, 'github_info', true ) )
								) {
									$gh_user_details = $this->get_person_details( $term_id_object['slug'] );
									if ( ! empty( $gh_user_details ) ) {
										// Add a bio and change the nice name.
										$person_args = array(
											'name'        => esc_html( $gh_user_details['name'] ),
											'description' => esc_html( $gh_user_details['bio'] ),
										);
										wp_update_term( $term_id, 'ghactivity_actor', $person_args );

										// Save all the info as term meta.
										update_term_meta( $term_id, 'github_info', $gh_user_details );
									}
								}
							}
						}
					} // End foreach().
				}
			}

			$this->update_issue_records();
		}
	}

	/**
	 * Record data about each one of our issues in the ghactivity_issue CPT.
	 *
	 * @since 2.0.0
	 *
	 * @param array $issue_details {
	 * 	Array of information about the issue.
	 * 		@type string $type       issue or pull_request.
	 * 		@type string $event_type What kind of event was this.
	 * 		@type string created_at  When did this happen.
	 * 		@type int    $number     Issue Number.
	 * 		@type string $repo_name  Repo name.
	 * 		@type string $state      Issue state (open or closed).
	 * 		@type string $title      Issue title.
	 * 		@type int    $comments   Number of comments on the issue.
	 * 		@type string $creator    Issue creator.
	 * 		@type array  $labels     Array of labels for that issue.
	 * }
	 */
	private function record_issue_details( $issue_details ) {
		/**
		 * Create a new post if that issue does not exist yet.
		 * Update the post if not.
		 * We make a WP_Query and set $is_new to help us figure this out.
		 */
		$is_new_args = array(
			'post_type'      => 'ghactivity_issue',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'tax_query'      => array(
				array(
					'taxonomy' => 'ghactivity_repo',
					'field'    => 'name',
					'terms'    => $issue_details['repo_name'],
				),
			),
			'meta_query' => array(
				array(
					'key'     => 'number',
					'value'   => $issue_details['number'],
					'compare' => '=',
				),
			),
		);
		$query = new WP_Query( $is_new_args );
		if ( $query->have_posts() ) {
			$query->the_post();

			$is_new = false;
			$post_id = $query->post->ID;
		} else {
			$is_new = true;
		}
		wp_reset_postdata();

		if ( $is_new ) {
			// Create taxonomies.
			$taxonomies = array(
				'ghactivity_repo'          => $issue_details['repo_name'],
				'ghactivity_actor'         => $issue_details['creator'],
				'ghactivity_issues_state'  => $issue_details['state'],
				'ghactivity_issues_labels' => $issue_details['labels'],
				'ghactivity_issues_type'   => $issue_details['type'],
			);

			$meta = array(
				'number'   => absint( $issue_details['number'] ),
				'comments' => absint( $issue_details['comments'] ),
			);

			$post_content = sprintf(
				'<ul>
					<li><a href="https://github.com/%1$s/issues/%2$s">%3$s</a></li>
					<li>%4$s %5$s</li>
					<li>Comments: %6$s</li>
				</ul>',
				esc_attr( $issue_details['repo_name'] ),
				absint( $issue_details['number'] ),
				esc_html__( 'View original issue.', 'ghactivity' ),
				esc_html__( 'Labels:', 'ghactivity' ),
				implode( ', ', $issue_details['labels'] ),
				absint( $issue_details['comments'] )
			);
			$issue_args = array(
				'post_title'   => $issue_details['title'],
				'post_type'    => 'ghactivity_issue',
				'post_status'  => 'publish',
				'post_date'    => $issue_details['created_at'],
				'tax_input'    => $taxonomies,
				'meta_input'   => $meta,
				'post_content' => $post_content,
			);
			$post_id = wp_insert_post( $issue_args );

			/**
			 * Establish the relationship between terms and taxonomies.
			 */
			foreach ( $taxonomies as $taxonomy => $value ) {
				$term_taxonomy_ids = wp_set_object_terms( $post_id, $value, $taxonomy, true );
			}
		} else {
			$taxonomies = array(
				'ghactivity_issues_state'  => $issue_details['state'],
				'ghactivity_issues_labels' => $issue_details['labels'],
			);
			$meta = array(
				'number'   => absint( $issue_details['number'] ),
				'comments' => absint( $issue_details['comments'] ),
			);
			$post_content = sprintf(
				'<ul>
					<li><a href="https://github.com/%1$s/issues/%2$s">%3$s</a></li>
					<li>%4$s %5$s</li>
					<li>Comments: %6$s</li>
				</ul>',
				esc_attr( $issue_details['repo_name'] ),
				absint( $issue_details['number'] ),
				esc_html__( 'View original issue.', 'ghactivity' ),
				esc_html__( 'Labels:', 'ghactivity' ),
				implode( ', ', $issue_details['labels'] ),
				absint( $issue_details['comments'] )
			);

			$issue_args = array(
				'ID'           => $post_id,
				'post_title'   => $issue_details['title'],
				'meta_input'   => $meta,
				'tax_input'    => $taxonomies,
				'post_content' => $post_content,
			);
			wp_update_post( $issue_args );

			/**
			 * Establish the relationship between terms and taxonomies.
			 */
			foreach ( $taxonomies as $taxonomy => $value ) {
				$term_taxonomy_ids = wp_set_object_terms( $post_id, $value, $taxonomy, false );
			}
		} // End if() $is_new.
	}

	/**
	 * Sort events by its creation date in ascending order
	 *
	 * @param Object $a Event object as it returned from Github API.
	 * @param Object $b Event object as it returned from Github API.
	 */
	private function sort_by_date( $a, $b ) {
		return ( strtotime( $a->created_at ) < strtotime( $b->created_at ) ) ? -1 : 1;
	}

	/**
	 * Record any label updates into taxonomy meta of issue post.
	 * It designed to work with repository issues events & with specific issue events.
	 * To make it work with latter - $options array should be passed with post_id, repo_name, issue_number values
	 *
	 * @since 2.1
	 *
	 * @param array $event_list Event object as it returned from Github API.
	 * @param array $options List of options which is used when passing list issue-specific events.
	 */
	public function update_issue_records( $event_list = null, $options = null ) {
		if ( ! is_array( $event_list ) && ! is_array( $options ) ) {
			$event_list = $this->get_github_issue_events();
		}

		if ( ! isset( $event_list ) || ! is_array( $event_list ) ) {
			return;
		}

		// Sorts all the events by created date from older to newer.
		usort( $event_list, array( 'GHActivity_Calls', 'sort_by_date' ) );

		foreach ( $event_list as $event ) {
			// process only specific event types.
			if ( 'labeled' !== $event->event
			&& 'unlabeled' !== $event->event
			&& 'closed' !== $event->event
			&& 'reopened' !== $event->event ) {
				continue;
			}

			if ( is_array( $options ) && $options['issue_number'] && $options['repo_name'] ) {
				$issue_number = $options['issue_number'];
				$repo_name    = $options['repo_name'];
				$post_id      = $options['post_id'];
			} else {
				preg_match( '/(?<=repos\/)(.*?)(?=\/issues)/', $event->url, $match );
				$issue_number = $event->issue->number;
				$repo_name    = $match[0];
				$post_id      = $this->find_post( $repo_name, $issue_number );
			}

			$slug = $repo_name . '#' . $issue_number;
			if ( ! $post_id ) {
				continue;
			}

			// If issue is closed/reopened - update ghactivity_issues_state accordingly, and continue to next event.
			if ( 'closed' === $event->event ) {
				wp_set_post_terms( $post_id, 'closed', 'ghactivity_issues_state', false );
				continue;
			} elseif ( 'reopened' === $event->event ) {
				wp_set_post_terms( $post_id, 'open', 'ghactivity_issues_state', false );
				continue;
			}

			// Update label list according to event data.
			if ( 'labeled' === $event->event ) { // Add missing labels if needed.
				wp_set_post_terms( $post_id, $event->label->name, 'ghactivity_issues_labels', true );
			} elseif ( 'unlabeled' === $event->event ) {
				wp_remove_object_terms( $post_id, $event->label->name, 'ghactivity_issues_labels' );
			}

			$query = array(
				'taxonomy' => 'ghactivity_issues_labels',
				'name'     => $event->label->name,
			);
			$term  = get_terms( $query );
			if ( ! is_array( $term ) || empty( $term ) ) {
				continue;
			}
			$term = $term[0];
			/**
			 * If this is labeled/unlabeled event - update label meta to include event data.
			 * Since ghactivity_issues_labels terms are shared between all the issues
			 * we need to store term metadata (label status, labeled/unlabeled date) as an array
			 * Expected key/value pair:
			 *  automattic/jetpack#5432 => [
			 *    'status'    => labeled,
			 *    'labeled'   => 2018-07-10T21:52:02Z",
			 *    'unlabeled' => null,
			 *  ]
			 */
			$record = array(
				'status'    => null,
				'labeled'   => null,
				'unlabeled' => null,
			);
			if ( metadata_exists( 'term', $term->term_id, $slug ) ) {
				$record = get_term_meta( $term->term_id, $slug, true );
			}
			$record['status']        = $event->event;
			$record[ $event->event ] = $event->created_at;
			update_term_meta( $term->term_id, $slug, $record );
		}
	}

	/**
	 * Search for a exisiting `ghactivity_issue` post
	 * Return post_id if found, and null if not.
	 *
	 * @param string $repo_name name of the repo.
	 * @param int    $issue_number issue number.
	 *
	 * @return int $post_id ID of the post. Null if not found.
	 */
	public function find_post( $repo_name, $issue_number ) {
		$post_id     = null;
		$is_new_args = array(
			'post_type'      => 'ghactivity_issue',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'tax_query'      => array(
				array(
					'taxonomy' => 'ghactivity_repo',
					'field'    => 'name',
					'terms'    => $repo_name,
				),
			),
			'meta_query' => array(
				array(
					'key'     => 'number',
					'value'   => $issue_number,
					'compare' => '=',
				),
			),
		);
		$query = new WP_Query( $is_new_args );
		if ( $query->have_posts() ) {
			$query->the_post();
			$post_id = $query->post->ID;
		}
		wp_reset_postdata();

		return $post_id;
	}


	/**
	 * Remote call to get all label events for every monitored repo
	 *
	 * @since 2.1.0
	 *
	 * @param string $repo         name of the repo.
	 * @param int    $issue_number issue number.
	 *
	 * @return array
	 */
	public function get_github_issue_events( $repo = null, $issue_number = null ) {
		$response_body    = array();

		if ( empty( $repo ) ) {
			$repos_to_query = $this->get_monitored_repos( 'names' );
			if ( empty( $repos_to_query ) ) {
				return $response_body;
			}
		} else {
			$repos_to_query = array( $repo );
		}

		foreach ( $repos_to_query as $repo_name ) {
			$query_url     = sprintf(
				'https://api.github.com/repos/%1$s/issues%2$s/events?access_token=%3$s&per_page=100',
				esc_html( $repo_name ),
				esc_html( $issue_number ? '/' . $issue_number : '' ),
				$this->get_option( 'access_token' )
			);
			$single_response_body = $this->get_github_data( $query_url );

			$response_body        = array_merge( $single_response_body, $response_body );
		}
		return $response_body;
	}
}
new GHActivity_Calls();
